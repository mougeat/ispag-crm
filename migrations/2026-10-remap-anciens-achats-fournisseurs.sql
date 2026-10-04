-- ============================================================================
-- Rattrapage : anciens achats restes sur l'ancien Id fournisseur
--   wor9711_achats_commande_liste_fournisseurs.IdFournisseur
--   ancien achats_fournisseurs.Id  ->  ispag_companies.Id
--
-- POURQUOI UN SCRIPT DE PLUS : 2026-09-remap-references-fournisseurs.sql est protege par un drapeau
-- (wor9711_supplier_remap_done) et ne s'applique qu'une fois ; si des achats sont restes sur l'ancien Id,
-- il ne les corrigera pas. Celui-ci cible uniquement les achats ENCORE sur l'ancien Id.
--
-- LE PIEGE : un Id seul ne dit pas s'il est ancien ou nouveau (l'Id 400 existe dans les deux tables, pour deux
-- societes differentes). On ne remappe donc QUE les achats crees AVANT la date de migration (@cutoff ci-dessous) :
-- apres cette date, les achats ont ete saisis avec les nouveaux Id et ne doivent pas etre touches.
--
-- ORDRE : faire un dump -> ETAPE 0 (lecture seule) -> choisir @cutoff -> ETAPE 1 -> ETAPE 2 (controle).
-- PREREQUIS : wor9711_achats_fournisseurs existe encore (ne pas la supprimer avant la fin).
-- ============================================================================

-- ETAPE 0 : DIAGNOSTIC (lecture seule) -----------------------------------------

-- 0a. Table de correspondance ancien Id -> ispag_companies.Id (domaine d'abord, puis nom exact ; rien n'est cree).
DROP TABLE IF EXISTS wor9711_tmp_supplier_map_orders;
CREATE TABLE wor9711_tmp_supplier_map_orders (
  old_id     INT NOT NULL PRIMARY KEY,
  company_id BIGINT NOT NULL,
  match_type VARCHAR(10) NOT NULL
);

INSERT INTO wor9711_tmp_supplier_map_orders (old_id, company_id, match_type)
SELECT f.Id, MIN(c.Id), 'domain'
FROM wor9711_achats_fournisseurs f
JOIN wor9711_ispag_companies c
  ON LOWER(c.compagny_domain) = LOWER(TRIM(f.compagnyDomain)) COLLATE utf8mb4_unicode_ci
WHERE TRIM(f.compagnyDomain) <> ''
GROUP BY f.Id;

INSERT INTO wor9711_tmp_supplier_map_orders (old_id, company_id, match_type)
SELECT f.Id, MIN(c.Id), 'name'
FROM wor9711_achats_fournisseurs f
JOIN wor9711_ispag_companies c ON LOWER(c.company_name) = LOWER(f.Fournisseur) COLLATE utf8mb4_unicode_ci
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map_orders)
GROUP BY f.Id;

-- 0b. Anciens fournisseurs SANS correspondance (a traiter a la main : leurs achats ne seront pas remappes)
SELECT f.Id AS old_id, f.Fournisseur
FROM wor9711_achats_fournisseurs f
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map_orders);

-- 0c. Rapprochements par NOM (moins surs que par domaine) : a relire
SELECT m.old_id, f.Fournisseur AS ancien_nom, m.company_id, c.company_name AS nouveau_nom
FROM wor9711_tmp_supplier_map_orders m
JOIN wor9711_achats_fournisseurs f ON f.Id = m.old_id
JOIN wor9711_ispag_companies c ON c.Id = m.company_id
WHERE m.match_type = 'name';

-- 0d. Achats dont l'Id ressemble a un ANCIEN Id : le nom (ancienne table) differe du nom de la societe portant le meme Id
--     (nouvelle table). Regarder les dates : les achats recents (apres la migration) ne devraient PAS figurer ici.
SELECT FROM_UNIXTIME(MIN(t.TimestampDateCreation)) AS plus_ancien,
       FROM_UNIXTIME(MAX(t.TimestampDateCreation)) AS plus_recent,
       COUNT(*) AS achats_incoherents
FROM wor9711_achats_commande_liste_fournisseurs t
JOIN wor9711_achats_fournisseurs f ON f.Id = t.IdFournisseur
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
WHERE LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, '')) COLLATE utf8mb4_unicode_ci;

--     Detail par mois : repere la date de bascule (les mois d'avant sont a corriger, ceux d'apres sont deja bons).
SELECT DATE_FORMAT(FROM_UNIXTIME(t.TimestampDateCreation), '%Y-%m') AS mois,
       COUNT(*) AS achats_total,
       SUM(LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, '')) COLLATE utf8mb4_unicode_ci) AS incoherents
FROM wor9711_achats_commande_liste_fournisseurs t
LEFT JOIN wor9711_achats_fournisseurs f ON f.Id = t.IdFournisseur
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
GROUP BY mois
ORDER BY mois;

-- ETAPE 1 : SAUVEGARDE + REMAPPAGE (a lancer apres l'etape 0, en une execution separee) --------
-- >>> Renseigner la date de bascule : les achats crees STRICTEMENT AVANT cette date sont remappes. <<<
-- Exemple : '2026-09-01 00:00:00'. Laisser NULL = aucune modification (securite).
SET @cutoff = NULL;

CREATE TABLE IF NOT EXISTS wor9711_backup_commande_liste_supplier_2026_10 (
  Id INT NOT NULL PRIMARY KEY, old_value INT NOT NULL, new_value BIGINT NOT NULL, done_at DATETIME NOT NULL
);

-- Sauvegarde des lignes qui vont changer (ancien et nouvel Id)
INSERT IGNORE INTO wor9711_backup_commande_liste_supplier_2026_10 (Id, old_value, new_value, done_at)
SELECT t.Id, t.IdFournisseur, m.company_id, NOW()
FROM wor9711_achats_commande_liste_fournisseurs t
JOIN wor9711_tmp_supplier_map_orders m ON m.old_id = t.IdFournisseur
JOIN wor9711_achats_fournisseurs f ON f.Id = t.IdFournisseur
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
WHERE @cutoff IS NOT NULL
  AND t.TimestampDateCreation < UNIX_TIMESTAMP(@cutoff)
  AND m.company_id <> t.IdFournisseur
  AND LOWER(f.Fournisseur) <> LOWER(IFNULL(c.company_name, '')) COLLATE utf8mb4_unicode_ci;

-- Remappage (uniquement les lignes sauvegardees ci-dessus et encore sur l'ancien Id : relancer ce script ne fait rien de plus)
UPDATE wor9711_achats_commande_liste_fournisseurs t
JOIN wor9711_backup_commande_liste_supplier_2026_10 b ON b.Id = t.Id AND t.IdFournisseur = b.old_value
SET t.IdFournisseur = b.new_value;

-- ETAPE 2 : controles ------------------------------------------------------------
-- 2a. Nombre d'achats remappes
SELECT COUNT(*) AS achats_remappes FROM wor9711_backup_commande_liste_supplier_2026_10;

-- 2b. Doit renvoyer 0 ligne : achats dont le fournisseur n'existe pas dans ispag_companies
SELECT t.Id, t.IdFournisseur FROM wor9711_achats_commande_liste_fournisseurs t
LEFT JOIN wor9711_ispag_companies c ON c.Id = t.IdFournisseur
WHERE t.IdFournisseur <> 0 AND c.Id IS NULL;

-- ANNULER (retour arriere) :
-- UPDATE wor9711_achats_commande_liste_fournisseurs t
-- JOIN wor9711_backup_commande_liste_supplier_2026_10 b ON b.Id = t.Id AND t.IdFournisseur = b.new_value
-- SET t.IdFournisseur = b.old_value;

-- NON COUVERT ICI (a traiter de la meme facon si le diagnostic le montre) :
--   wor9711_achats_details_commande.IdFournisseur et wor9711_achats_articles_purchase.supplier_id
--   (pas de date de creation exploitable : voir 2026-09-remap-references-fournisseurs.sql, etape 0).
