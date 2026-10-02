#!/usr/bin/env python3
# Filtre l'export La bonne alternance (JSON ~600 Mo) en JSONL d'offres réelles, normalisées pour la table jobs.
# Exclut les « recruteurs_lba » (candidatures spontanées, pas des offres) et les offres France Travail (déjà importées).
import json, re, sys

DEP_REG = {}
for reg, deps in {
    '84': '01 03 07 15 26 38 42 43 63 69 73 74', '27': '21 25 39 58 70 71 89 90', '53': '22 29 35 56',
    '24': '18 28 36 37 41 45', '94': '20', '44': '08 10 51 52 54 55 57 67 68 88', '32': '02 59 60 62 80',
    '11': '75 77 78 91 92 93 94 95', '28': '14 27 50 61 76', '75': '16 17 19 23 24 33 40 47 64 79 86 87',
    '76': '09 11 12 30 31 32 34 46 48 65 66 81 82', '52': '44 49 53 72 85', '93': '04 05 06 13 83 84',
    '01': '971', '02': '972', '03': '973', '04': '974', '06': '976'}.items():
    for d in deps.split():
        DEP_REG[d] = reg

def region(postal):
    if not postal:
        return None
    return DEP_REG.get(postal[:3]) if postal.startswith('97') else DEP_REG.get(postal[:2])

src, out = sys.argv[1], sys.argv[2]
out_rec = sys.argv[3] if len(sys.argv) > 3 else None
data = json.load(open(src, encoding='utf-8'))
n = 0
fr = open(out_rec, 'w', encoding='utf-8') if out_rec else None
with open(out, 'w', encoding='utf-8') as f:
    for x in data:
        ident = x.get('identifier') or {}
        if ident.get('partner_label') == 'recruteurs_lba':
            # entreprises susceptibles de recruter en alternance (candidature spontanée)
            if fr:
                w, a = x.get('workplace') or {}, x.get('apply') or {}
                loc = w.get('location') or {}
                m = re.search(r'\b(\d{5})\b\s*(.*)$', (loc.get('address') or '').strip())
                if m and region(m.group(1)) and a.get('url'):
                    co = ((loc.get('geopoint') or {}).get('coordinates') or [None, None])
                    fr.write(json.dumps({'siret': w.get('siret') or ident.get('partner_job_id'), 'name': (w.get('brand') or w.get('name') or w.get('legal_name') or '').strip(),
                        'naf': ((w.get('domain') or {}).get('naf') or {}).get('label') or '', 'size': w.get('size') or '', 'city': m.group(2).strip().title(),
                        'postal': m.group(1), 'region': region(m.group(1)), 'lon': co[0], 'lat': co[1], 'url': a['url']}, ensure_ascii=False) + '\n')
            continue
        if ident.get('partner_label') == 'France Travail':
            continue
        o, w, c, a = x.get('offer') or {}, x.get('workplace') or {}, x.get('contract') or {}, x.get('apply') or {}
        if o.get('status') != 'Active' or not o.get('title') or not a.get('url'):
            continue
        addr = ((w.get('location') or {}).get('address') or '').strip()
        m = re.search(r'\b(\d{5})\b\s*(.*)$', addr)
        postal, city = (m.group(1), m.group(2).strip().title()) if m else ('', '')
        reg = region(postal)
        if not reg:
            continue
        desc = (o.get('description') or '').strip()
        for label, key in (('Compétences attendues', 'desired_skills'), ('Compétences à acquérir', 'to_be_acquired_skills'), ("Conditions d'accès", 'access_conditions')):
            items = [s for s in (o.get(key) or []) if isinstance(s, str) and s.strip()]
            if items:
                desc += '\n\n' + label + ' :\n- ' + '\n- '.join(items)
        if (w.get('description') or '').strip():
            desc += "\n\nL'entreprise :\n" + w['description'].strip()
        types = [t for t in (c.get('type') or []) if t]
        dur = c.get('duration')
        dipl = (o.get('target_diploma') or {}).get('label') or ''
        f.write(json.dumps({
            'id': 'lba-' + str(ident.get('id')), 'src': 'La bonne alternance' + (' — ' + ident['partner_label'] if ident.get('partner_label') not in (None, 'offres_emploi_lba') else ''),
            'title': o['title'].strip(), 'company': (w.get('brand') or w.get('name') or w.get('legal_name') or '').strip(),
            'description': desc, 'city': city, 'postal': postal, 'region': reg, 'contract': 'alternance',
            'contract_label': ' / '.join(('Contrat d\'apprentissage' if t == 'Apprentissage' else 'Contrat de professionnalisation' if t == 'Professionnalisation' else t) for t in types) or 'Alternance',
            'worktime': (str(dur) + ' mois') if dur else '', 'salary': '', 'experience': ('Diplôme visé : ' + dipl) if dipl else '',
            'sector': ((w.get('domain') or {}).get('naf') or {}).get('label') or '', 'url': a['url'],
            'created_at': ((o.get('publication') or {}).get('creation') or '')[:19].replace('T', ' '),
            '_romes': ','.join(o.get('rome_codes') or []), '_lon': ((w.get('location') or {}).get('geopoint') or {}).get('coordinates', [None, None])[0], '_lat': ((w.get('location') or {}).get('geopoint') or {}).get('coordinates', [None, None])[1],
        }, ensure_ascii=False) + '\n')
        n += 1
if fr:
    fr.close()
print(n)
