-- Mapping discipline CRM -> famille ressource (parametrable depuis l'admin)
CREATE TABLE IF NOT EXISTS mapping_discipline (
  crm_label    TEXT PRIMARY KEY,
  famille_slug TEXT NOT NULL
);
INSERT OR IGNORE INTO mapping_discipline (crm_label, famille_slug) VALUES
 ('01 - QIGONG','qigong'),
 ('02 - Visio Qigong','qigong'),
 ('03 - DAOYIN','qigong'),
 ('10 - Visio Daoyin','qigong'),
 ('08 - Waigong','qigong'),
 ('09 - MEDITATION','qigong'),
 ('04 - TAICHI Chuan','taichi'),
 ('05 - TAICHI - Chi Kung','taichi'),
 ('07 - TAICHI Tuishou','taichi'),
 ('06 - KUNGFU','kungfu'),
 ('11 - Cours enfant','qigong_enfant');
CREATE TABLE IF NOT EXISTS reglages (cle TEXT PRIMARY KEY, valeur TEXT DEFAULT '');
INSERT OR IGNORE INTO reglages (cle, valeur) VALUES ('saison_courante','2025/2026');
