#!/usr/bin/env python3
"""Randonnées balisées OpenStreetMap (VPS, cron mensuel) → annuaire « randos » de la fabrique.
Overpass n'est pas joignable depuis l'hébergement mutualisé : le VPS interroge OSM, situe chaque itinéraire
(commune de départ via geo.api.gouv.fr) et pousse les fiches par /_api/places.
Config : /opt/fabrique/randos.env (FAB_API, FAB_TOKEN, FAB_HOST, DEPTS=14,27,50,61,76)."""
import json, os, time, urllib.parse, urllib.request

env = dict(l.strip().split('=', 1) for l in open('/opt/fabrique/randos.env') if '=' in l and not l.startswith('#'))
UA = {'User-Agent': 'fabrique-randos/1.0 (OpenStreetMap ODbL)'}
NETWORK = {'iwn': 'GR (itinéraire international)', 'nwn': 'GR (grande randonnée)', 'rwn': 'GR de Pays / itinéraire régional', 'lwn': 'PR (promenade et randonnée)'}


def get(url, data=None, timeout=180):
    req = urllib.request.Request(url, data=data, headers=UA)
    with urllib.request.urlopen(req, timeout=timeout) as r:
        return json.loads(r.read().decode())


def commune(lat, lon):
    try:
        c = get(f'https://geo.api.gouv.fr/communes?lat={lat}&lon={lon}&fields=nom,codesPostaux&limit=1', timeout=20)
        if c:
            return c[0]['nom'], (c[0].get('codesPostaux') or [''])[0]
    except Exception:
        pass
    return None, None


rows, seen, failed = [], set(), False
for dept in env.get('DEPTS', '14,27,50,61,76').split(','):
    q = f'[out:json][timeout:120];area["ref:INSEE"="{dept}"]["admin_level"="6"]->.a;rel(area.a)["route"="hiking"];out center tags;'
    els = None
    for attempt in range(4):  # Overpass renvoie souvent 504/429 : on réessaie
        try:
            els = get('https://overpass-api.de/api/interpreter', urllib.parse.urlencode({'data': q}).encode())['elements']
            break
        except Exception as e:
            print('overpass', dept, e); time.sleep(60)
    if els is None:
        failed = True; continue
    print(dept, len(els), 'itinéraires')
    for e in els:
        t, c = e.get('tags', {}), e.get('center')
        if e['id'] in seen or not c or not (t.get('name') or t.get('ref')):
            continue
        seen.add(e['id'])
        city, cp = commune(c['lat'], c['lon'])
        time.sleep(0.05)
        if not cp or not cp.startswith(dept[:2]):
            continue
        name = t.get('name') or t.get('ref')
        if t.get('ref') and t['ref'] not in name:
            name = f"{t['ref']} — {name}"
        dist = t.get('distance', '')
        fields = {k: v for k, v in {
            'Type': NETWORK.get(t.get('network', ''), ''), 'Référence': t.get('ref', ''), 'Départ': t.get('from', ''), 'Arrivée': t.get('to', ''),
            'Distance': (dist + ' km') if dist and dist.replace('.', '').isdigit() else dist, 'Boucle': 'oui' if t.get('roundtrip') == 'yes' else '',
            'Balisage': t.get('osmc:symbol', '').split(':')[0], 'Gestionnaire': t.get('operator', ''), 'Durée indicative': t.get('duration', '')}.items() if v}
        links = [['Tracé sur Waymarked Trails', f"https://hiking.waymarkedtrails.org/#route?id={e['id']}"], ['Fiche OpenStreetMap', f"https://www.openstreetmap.org/relation/{e['id']}"]]
        if t.get('website'):
            links.insert(0, ['Site officiel', t['website']])
        rank = {'iwn': 60, 'nwn': 50, 'rwn': 40, 'lwn': 20}.get(t.get('network', ''), 10) + (15 if t.get('description') else 0) + (10 if dist else 0)
        rows.append({'id': f"osm{e['id']}", 'name': name, 'kind': NETWORK.get(t.get('network', ''), 'Randonnée balisée'), 'address': t.get('from', ''), 'cp': cp, 'city': city,
                     'lat': c['lat'], 'lon': c['lon'], 'rank': rank, 'fields': fields, 'desc': t.get('description', '') or t.get('note', ''), 'links': links})
    time.sleep(5)

print(len(rows), 'fiches')
stamp = time.strftime('%Y-%m-%d %H:%M:%S', time.gmtime())
for i in range(0, len(rows), 400):
    body = json.dumps({'host': env['FAB_HOST'], 'rows': rows[i:i + 400], 'stamp': stamp, 'final': i + 400 >= len(rows) and len(rows) > 100 and not failed}).encode()
    req = urllib.request.Request(env['FAB_API'].rstrip('/') + '/_api/places', data=body, headers={**UA, 'Content-Type': 'application/json', 'X-Fabrique-Token': env['FAB_TOKEN']})
    with urllib.request.urlopen(req, timeout=120) as r:
        print(r.read().decode())
