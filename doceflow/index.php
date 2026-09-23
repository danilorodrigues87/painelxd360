<?php

/**
 * Ponte Opção A: mesmo host do painel → app DoceFlow em /doceflow/
 * Repassa para C:\xampp\htdocs\pjt\doceflow\public\index.php
 */
declare(strict_types=1);

$doceflowFront = dirname(__DIR__, 2) . '/doceflow/public/index.php';
if (!is_file($doceflowFront)) {
	http_response_code(503);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'DoceFlow não instalado (pasta pjt/doceflow).';
	exit;
}

require $doceflowFront;
