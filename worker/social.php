<?php

/**
 * Worker de publicação social (Facebook / Instagram).
 * Uso CLI: php worker/social.php [id_admin] [limite]
 * Cron Linux (a cada 5 min):
 *   */5 * * * * php /caminho/painel-cti/worker/social.php >> /var/log/social-worker.log 2>&1
 * Ou URL (cPanel): GET {URL}/cron/social?token={SYSTEM_TOKEN}
 */

require __DIR__.'/../includes/app.php';

use App\Common\Helpers\SocialPublishService;
use App\Model\Entity\SocialPost;

if (!SocialPost::tabelaExiste()) {
	fwrite(STDERR, "Tabela social_posts não existe. Execute database/social_posts.sql\n");
	exit(1);
}

$idAdmin = isset($argv[1]) ? (int)$argv[1] : 0;
$limite = isset($argv[2]) ? (int)$argv[2] : 10;

$resumo = SocialPublishService::processar($idAdmin, $limite, 'cli');

echo json_encode($resumo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
