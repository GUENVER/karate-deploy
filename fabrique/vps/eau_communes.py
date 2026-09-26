#!/usr/bin/env python3
"""Qualité de l'eau du robinet par commune (VPS, cron mensuel) → fiches communes de la fabrique.
Fichier annuel du contrôle sanitaire (ministère de la Santé, data.gouv.fr, ~1,2 Go décompressé) : trop lourd
pour l'hébergement mutualisé, agrégé ici puis poussé par /_api/commune_extra (kind=eau).
Config : /opt/fabrique/randos.env (FAB_API, FAB_TOKEN) + COMMUNE_HOST."""
import csv, io, json, time, urllib.error, urllib.request, zipfile
from collections import defaultdict

env = dict(l.strip().split('=', 1) for l in open('/opt/fabrique/randos.env') if '=' in l and not l.startswith('#'))
HOST = env.get('COMMUNE_HOST', 'ma-commune.decouverte.org')
UA = {'User-Agent': 'fabrique-eau/1.0'}
DATASET = '5cf8d9ed8b4c4110294c841d'
ZIP = '/opt/fabrique/eau/dis.zip'

ds = json.load(urllib.request.urlopen(urllib.request.Request(f'https://www.data.gouv.fr/api/1/datasets/{DATASET}/', headers=UA), timeout=60))
# fichier national de l'année la plus récente ayant assez de recul (on préfère l'année précédente complète après avril)
res = sorted([r for r in ds['resources'] if r['title'].startswith('dis-') and r['title'].endswith('.zip') and '-dept' not in r['title']], key=lambda r: r['title'], reverse=True)
pick = res[1] if len(res) > 1 and time.gmtime().tm_mon <= 4 else res[0]
year = pick['title'][4:8]
print('fichier', pick['title'])
urllib.request.urlretrieve(pick['url'], ZIP)
z = zipfile.ZipFile(ZIP)
name = lambda part: next(n for n in z.namelist() if part in n)
rd = lambda part: csv.DictReader(io.TextIOWrapper(z.open(name(part)), encoding='utf-8', errors='replace'))

# commune → réseaux (UDI)
com_udi, udi_name = defaultdict(set), {}
for r in rd('COM_UDI'):
    com_udi[r['inseecommune']].add(r['cdreseau']); udi_name[r['cdreseau']] = r['nomreseau']

# prélèvements par réseau
plv_udi, udi = {}, defaultdict(lambda: {'n': 0, 'b': 0, 'bt': 0, 'c': 0, 'ct': 0, 'last': ('', '')})
for r in rd('_PLV_'):
    u = udi[r['cdreseau']]; plv_udi[r['referenceprel']] = r['cdreseau']
    u['n'] += 1  # conformité calculée sur les seuls prélèvements où le critère a été analysé (C conforme / N non conforme)
    u['b'] += r['plvconformitebacterio'] == 'C'; u['bt'] += r['plvconformitebacterio'] in ('C', 'N')
    u['c'] += r['plvconformitechimique'] == 'C'; u['ct'] += r['plvconformitechimique'] in ('C', 'N')
    if r['dateprel'] > u['last'][0]:
        u['last'] = (r['dateprel'], r['conclusionprel'])
print(len(udi), 'réseaux,', len(plv_udi), 'prélèvements')

# nitrates et dureté (lecture en flux du gros fichier de résultats)
val = defaultdict(lambda: {'NO3': [], 'TH': []})
for r in rd('RESULT'):
    p = r['cdparametresiseeaux']
    if p in ('NO3', 'TH') and r['referenceprel'] in plv_udi:
        try:
            val[plv_udi[r['referenceprel']]][p].append(float(r['valtraduite']))
        except ValueError:
            pass

rows = {}
for insee, us in com_udi.items():
    us = [x for x in us if udi[x]['n']]
    if not us:
        continue
    n = sum(udi[x]['n'] for x in us)
    main = max(us, key=lambda x: udi[x]['n'])
    last = max((udi[x]['last'] for x in us), key=lambda t: t[0])
    bt, ct = sum(udi[x]['bt'] for x in us), sum(udi[x]['ct'] for x in us)
    d = {'year': year, 'n': n, 'conf_bact': round(100 * sum(udi[x]['b'] for x in us) / bt, 1) if bt else None, 'conf_chim': round(100 * sum(udi[x]['c'] for x in us) / ct, 1) if ct else None,
         'reseau': udi_name.get(main, '').title(), 'last_date': last[0], 'last': last[1][:300]}
    for p, k in (('NO3', 'no3'), ('TH', 'th')):
        v = [y for x in us for y in val[x][p]]
        if v:
            d[k] = round(sum(v) / len(v), 1)
    rows[insee] = d
print(len(rows), 'communes')

items = list(rows.items())
for i in range(0, len(items), 1000):
    body = json.dumps({'host': HOST, 'kind': 'eau', 'rows': dict(items[i:i + 1000])}).encode()
    for attempt in range(5):  # base verrouillée pendant une synchronisation : on réessaie
        req = urllib.request.Request(env['FAB_API'].rstrip('/') + '/_api/commune_extra', data=body, headers={**UA, 'Content-Type': 'application/json', 'X-Fabrique-Token': env['FAB_TOKEN']})
        try:
            print(urllib.request.urlopen(req, timeout=120).read().decode()); break
        except urllib.error.HTTPError as e:
            print('erreur', e.code, e.read().decode()[:200]); time.sleep(60)
