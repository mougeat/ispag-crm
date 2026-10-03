-- ============================================================================
-- Remappage des references fournisseur : ancien achats_fournisseurs.Id -> ispag_companies.Id
-- Colonnes concernees (d'apres les schemas des plugins) :
--   wor9711_achats_details_commande.IdFournisseur          (articles des projets ; defaut '18')
--   wor9711_achats_commande_liste_fournisseurs.IdFournisseur (commandes d'achat)
--   wor9711_achats_articles_purchase.supplier_id            (prix d'achat par fournisseur / articles standards)
--
-- PREREQUIS : wor9711_tmp_supplier_map existe (cree par 2026-09-fournisseurs-vers-companies.sql, etape 1)
--             et wor9711_achats_fournisseurs existe encore (ne pas la supprimer avant la fin).
-- Ce script est protege : il ne s'applique qu'une fois (table wor9711_supplier_remap_done).
-- ============================================================================

-- ETAPE 0 : DIAGNOSTIC (lecture seule) -----------------------------------------
-- Pour chaque colonne : combien de lignes pointent sur un fournisseur dont le NOM (ancienne table) ne correspond pas
-- au nom de l'entreprise portant le meme Id (nouvelle table). Non nul => les references n'ont pas ete remappees.
SELECT 'achats_details_commande' AS tbl, COUNT(*) AS lignes,
       SUM(f.Id IS NOT NULL AND LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, ''))) AS incoherentes
FROM wor9711_achats_details_commande t
LEFT JOIN wor9711_achats_fournisseurs f ON f.Id = t.IdFournisseur
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
UNION ALL
SELECT 'achats_commande_liste_fournisseurs', COUNT(*),
       SUM(f.Id IS NOT NULL AND LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, '')))
FROM wor9711_achats_commande_liste_fournisseurs t
LEFT JOIN wor9711_achats_fournisseurs f ON f.Id = t.IdFournisseur
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
UNION ALL
SELECT 'achats_articles_purchase', COUNT(*),
       SUM(f.Id IS NOT NULL AND LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, '')))
FROM wor9711_achats_articles_purchase t
LEFT JOIN wor9711_achats_fournisseurs f ON f.Id = t.supplier_id
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.supplier_id;

-- Autres colonnes de reference fournisseur eventuellement oubliees (information_schema est interdit chez OVH :
-- on utilise SHOW COLUMNS, table par table ; ajouter vos autres tables achats_* si besoin) :
SHOW COLUMNS FROM wor9711_achats_articles LIKE '%ournisseur%';
SHOW COLUMNS FROM wor9711_achats_articles LIKE 'supplier%';
SHOW COLUMNS FROM wor9711_achats_liste_commande LIKE '%ournisseur%';
SHOW COLUMNS FROM wor9711_achats_articles_cmd_fournisseurs LIKE '%ournisseur%';

-- ETAPE 1 (a lancer seulement apres l'etape 0, en une execution separee) : SAUVEGARDE + REMAPPAGE (a executer si le diagnostic montre des lignes incoherentes) ---------
CREATE TABLE IF NOT EXISTS wor9711_supplier_remap_done (done_at DATETIME NOT NULL);

CREATE TABLE IF NOT EXISTS wor9711_backup_details_commande_supplier  AS SELECT Id, IdFournisseur AS old_value FROM wor9711_achats_details_commande;
CREATE TABLE IF NOT EXISTS wor9711_backup_commande_liste_supplier    AS SELECT Id, IdFournisseur AS old_value FROM wor9711_achats_commande_liste_fournisseurs;
CREATE TABLE IF NOT EXISTS wor9711_backup_articles_purchase_supplier AS SELECT Id, supplier_id   AS old_value FROM wor9711_achats_articles_purchase;

-- Garde : ne rien faire si deja execute
UPDATE wor9711_achats_details_commande t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.IdFournisseur
SET t.IdFournisseur = m.company_id
WHERE NOT EXISTS (SELECT 1 FROM wor9711_supplier_remap_done);

UPDATE wor9711_achats_commande_liste_fournisseurs t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.IdFournisseur
SET t.IdFournisseur = m.company_id
WHERE NOT EXISTS (SELECT 1 FROM wor9711_supplier_remap_done);

UPDATE wor9711_achats_articles_purchase t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.supplier_id
SET t.supplier_id = m.company_id
WHERE NOT EXISTS (SELECT 1 FROM wor9711_supplier_remap_done);

INSERT INTO wor9711_supplier_remap_done (done_at)
SELECT NOW() WHERE NOT EXISTS (SELECT 1 FROM wor9711_supplier_remap_done);

-- ETAPE 2 : valeur par defaut de achats_details_commande.IdFournisseur ('18' = ancien Id fournisseur) ----------
-- Remplacer <NOUVEL_ID> par le resultat de : SELECT company_id FROM wor9711_tmp_supplier_map WHERE old_id = 18;
-- ALTER TABLE wor9711_achats_details_commande ALTER COLUMN IdFournisseur SET DEFAULT <NOUVEL_ID>;

-- ETAPE 3 : controle (doit renvoyer 0 ligne) : articles dont le fournisseur n'existe pas dans ispag_companies
SELECT t.Id, t.IdFournisseur FROM wor9711_achats_details_commande t
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
WHERE t.IdFournisseur <> 0 AND c.Id IS NULL;

-- ANNULER (retour arriere) :
-- UPDATE wor9711_achats_details_commande t JOIN wor9711_backup_details_commande_supplier b ON b.Id = t.Id SET t.IdFournisseur = b.old_value;
-- UPDATE wor9711_achats_commande_liste_fournisseurs t JOIN wor9711_backup_commande_liste_supplier b ON b.Id = t.Id SET t.IdFournisseur = b.old_value;
-- UPDATE wor9711_achats_articles_purchase t JOIN wor9711_backup_articles_purchase_supplier b ON b.Id = t.Id SET t.supplier_id = b.old_value;
-- DROP TABLE wor9711_supplier_remap_done;
