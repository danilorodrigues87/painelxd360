-- Slugs para clientes existentes (subdomínio)
USE xd360;

UPDATE clientes_assinantes
SET slug = 'cliente-teste'
WHERE (slug IS NULL OR slug = '')
  AND (nome LIKE '%teste%' OR email LIKE '%teste%')
LIMIT 1;

UPDATE clientes_assinantes e
SET slug = LOWER(REPLACE(REPLACE(REPLACE(REPLACE(
  SUBSTRING_INDEX(SUBSTRING_INDEX(nome, ' ', 1), ' ', -1),
  ' ', '-'), '.', ''), '/', ''), ',', ''))
WHERE (slug IS NULL OR slug = '')
  AND nome IS NOT NULL AND nome != '';
