PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS disciplines (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, nom TEXT NOT NULL, couleur TEXT DEFAULT '#64748b', order_index INTEGER DEFAULT 0);
CREATE TABLE IF NOT EXISTS adherents (id INTEGER PRIMARY KEY AUTOINCREMENT, wp_id INTEGER DEFAULT 0, crm_id INTEGER DEFAULT 0, email TEXT NOT NULL UNIQUE, login TEXT DEFAULT '', display_name TEXT DEFAULT '', password_hash TEXT DEFAULT '', is_admin INTEGER DEFAULT 0, actif INTEGER DEFAULT 1, saison TEXT DEFAULT '', cree TEXT DEFAULT (datetime('now')), modifie TEXT DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS acces (adherent_id INTEGER NOT NULL REFERENCES adherents(id) ON DELETE CASCADE, discipline_id INTEGER NOT NULL REFERENCES disciplines(id) ON DELETE CASCADE, source TEXT DEFAULT 'crm', accorde_le TEXT DEFAULT (datetime('now')), PRIMARY KEY (adherent_id, discipline_id));
CREATE TABLE IF NOT EXISTS ressources (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT 'video', titre TEXT NOT NULL DEFAULT '', description TEXT DEFAULT '', youtube_id TEXT DEFAULT '', pdf_path TEXT DEFAULT '', thumbnail TEXT DEFAULT '', source_wp_id INTEGER DEFAULT 0, publie INTEGER DEFAULT 1, order_index INTEGER DEFAULT 0, cree TEXT DEFAULT (datetime('now')), modifie TEXT DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS ressource_discipline (ressource_id INTEGER NOT NULL REFERENCES ressources(id) ON DELETE CASCADE, discipline_id INTEGER NOT NULL REFERENCES disciplines(id) ON DELETE CASCADE, PRIMARY KEY (ressource_id, discipline_id));
CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, nom TEXT DEFAULT '', password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'editor', actif INTEGER DEFAULT 1, cree TEXT DEFAULT (datetime('now')));
CREATE TABLE IF NOT EXISTS journal (id INTEGER PRIMARY KEY AUTOINCREMENT, acteur TEXT DEFAULT '', action TEXT DEFAULT '', cible TEXT DEFAULT '', detail TEXT DEFAULT '', ip TEXT DEFAULT '', cree TEXT DEFAULT (datetime('now')));
CREATE INDEX IF NOT EXISTS idx_adherents_wp ON adherents(wp_id);
CREATE INDEX IF NOT EXISTS idx_acces_disc ON acces(discipline_id);
CREATE INDEX IF NOT EXISTS idx_resdisc_disc ON ressource_discipline(discipline_id);
INSERT OR IGNORE INTO disciplines (slug, nom, couleur, order_index) VALUES
 ('qigong','Qigong','#64748b',1),
 ('taichi','Taïchi Chuan','#475569',2),
 ('kungfu','Kungfu / Sanshou','#334155',3),
 ('qigong_enfant','Qigong enfants','#94a3b8',4);
