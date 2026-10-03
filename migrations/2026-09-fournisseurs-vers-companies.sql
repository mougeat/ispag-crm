-- ============================================================================
-- Migration : wor9711_achats_fournisseurs  ->  wor9711_ispag_companies (isSupplier = 1)
--
-- A LIRE AVANT D'EXECUTER
--  * Faire un dump complet de la base avant.
--  * A executer UNE SEULE FOIS (le remappage des IdFournisseur n'est pas idempotent).
--  * Nouvelle cle de reference des fournisseurs = wor9711_ispag_companies.Id.
--    Aucune correspondance ne passe par viag_id : rapprochement par domaine puis par nom.
--  * Les champs propres aux fournisseurs vont dans wor9711_ispag_companies_meta.
-- ============================================================================

-- 1. Table de correspondance ancien Id fournisseur -> ispag_companies.Id -------
DROP TABLE IF EXISTS wor9711_tmp_supplier_map;
CREATE TABLE wor9711_tmp_supplier_map (
  old_id     INT NOT NULL PRIMARY KEY,
  company_id BIGINT NOT NULL,
  match_type VARCHAR(10) NOT NULL
);

-- 1a. par domaine
INSERT INTO wor9711_tmp_supplier_map (old_id, company_id, match_type)
SELECT f.Id, MIN(c.Id), 'domain'
FROM wor9711_achats_fournisseurs f
JOIN wor9711_ispag_companies c
  ON LOWER(c.compagny_domain) = LOWER(TRIM(f.compagnyDomain)) COLLATE utf8mb4_unicode_ci
WHERE TRIM(f.compagnyDomain) <> ''
GROUP BY f.Id;

-- 1b. par nom exact pour ceux qui restent
INSERT INTO wor9711_tmp_supplier_map (old_id, company_id, match_type)
SELECT f.Id, MIN(c.Id), 'name'
FROM wor9711_achats_fournisseurs f
JOIN wor9711_ispag_companies c ON LOWER(c.company_name) = LOWER(f.Fournisseur) COLLATE utf8mb4_unicode_ci
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map)
GROUP BY f.Id;

-- 1c. les fournisseurs sans correspondance sont crees dans ispag_companies
INSERT INTO wor9711_ispag_companies (isSupplier, isIngenieur, company_name, compagny_domain, is_active, city, phone, email, created_at)
SELECT f.isSupplier, f.isIngenieur, f.Fournisseur, NULLIF(TRIM(f.compagnyDomain), ''), 1, NULLIF(f.Ville, ''), LEFT(NULLIF(f.NumTel, ''), 20), LEFT(NULLIF(f.Mail, ''), 100), NOW()
FROM wor9711_achats_fournisseurs f
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map);

INSERT INTO wor9711_tmp_supplier_map (old_id, company_id, match_type)
SELECT f.Id, MAX(c.Id), 'created'
FROM wor9711_achats_fournisseurs f
JOIN wor9711_ispag_companies c ON c.company_name = f.Fournisseur COLLATE utf8mb4_unicode_ci
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map)
GROUP BY f.Id;

-- Controle : doit renvoyer 0 ligne
SELECT f.Id, f.Fournisseur FROM wor9711_achats_fournisseurs f
WHERE f.Id NOT IN (SELECT old_id FROM wor9711_tmp_supplier_map);

-- A relire : rapprochements par nom (moins surs que par domaine)
SELECT m.old_id, f.Fournisseur, m.company_id, c.company_name
FROM wor9711_tmp_supplier_map m
JOIN wor9711_achats_fournisseurs f ON f.Id = m.old_id
JOIN wor9711_ispag_companies c ON c.Id = m.company_id
WHERE m.match_type = 'name';

-- 2. Drapeaux fournisseur / ingenieur ----------------------------------------
UPDATE wor9711_ispag_companies c
JOIN wor9711_tmp_supplier_map m ON m.company_id = c.Id
JOIN wor9711_achats_fournisseurs f ON f.Id = m.old_id
SET c.isSupplier  = GREATEST(c.isSupplier, f.isSupplier),
    c.isIngenieur = GREATEST(c.isIngenieur, f.isIngenieur);

-- 3. Champs propres aux fournisseurs -> wor9711_ispag_companies_meta ---------
--    (on n'ecrase jamais une meta existante)
INSERT INTO wor9711_ispag_companies_meta (company_id, meta_key, meta_value)
SELECT m.company_id, x.meta_key, x.meta_value
FROM wor9711_tmp_supplier_map m
JOIN (
            SELECT Id, 'ispag_supplier_lang'             AS meta_key, Langue               AS meta_value FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_currency',          Monnaie              FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_tva',               TVA                  FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_adress',             SupplierAdresse      FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_postal_code',        CodePostal           FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_city',               Ville                FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_region',             region               FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_country',            Pays                 FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_industry',           industry             FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_company_phone',              NumTel               FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_delivery_days',     deliveryDays         FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_transport_time',    TransportTime        FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_image',             Image                FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_contact_order',     IdContactCommande    FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_contact_plan',      IdContactPlan        FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_contact_billing',   IdContactFacturation FROM wor9711_achats_fournisseurs
  UNION ALL SELECT Id, 'ispag_supplier_contact_delivery',  IdContactLivraison   FROM wor9711_achats_fournisseurs
) x ON x.Id = m.old_id
WHERE x.meta_value IS NOT NULL AND x.meta_value <> '' AND x.meta_value <> '0'
  AND NOT EXISTS (
    SELECT 1 FROM wor9711_ispag_companies_meta e
    WHERE e.company_id = m.company_id AND e.meta_key = x.meta_key
  );

-- 4. Remappage des references (ancien Id fournisseur -> ispag_companies.Id) ---
--    Colonnes concernees : voir 2026-09-remap-references-fournisseurs.sql (SHOW COLUMNS ; information_schema est bloque chez OVH).
UPDATE wor9711_achats_commande_liste_fournisseurs t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.IdFournisseur
SET t.IdFournisseur = m.company_id;

UPDATE wor9711_achats_details_commande t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.IdFournisseur
SET t.IdFournisseur = m.company_id;

UPDATE wor9711_achats_articles_purchase t
JOIN wor9711_tmp_supplier_map m ON m.old_id = t.supplier_id
SET t.supplier_id = m.company_id;

-- 4b. A remapper aussi : IdFournisseur dans les articles standards (wor9711_achats_articles)
--     et toute autre colonne listee par la requete information_schema ci-dessus.

-- 5. Valeurs en dur dans le code a mettre a jour avec la nouvelle cle ---------
--    SELECT * FROM wor9711_tmp_supplier_map WHERE old_id IN (17, 25, 444);
--      17  -> ISPAG_Tank_Insulation_Auto_Saver ($default_supplier)
--      25  -> ISPAG_Tank_Welding_Auto_Saver    ($default_supplier)
--      444 -> ISPAG_Carrybox_Manager           ($id_carrybox)

-- 6. Nettoyage (apres validation en production) -------------------------------
-- DROP TABLE wor9711_tmp_supplier_map;
-- RENAME TABLE wor9711_achats_fournisseurs TO wor9711_achats_fournisseurs_old;
