<?php
/**
 * Diagnóstico de envio de áudio via Evolution.
 * Uso: php worker/teste-audio-evolution.php escola_1 5511999999999 [caminho/arquivo.mp3]
 */
require __DIR__.'/../includes/app.php';

use App\Common\Communication\EvolutionApiService;

$instance = $argv[1] ?? '';
$number = $argv[2] ?? '';
$file = $argv[3] ?? '';

if ($instance === '' || $number === '') {
	fwrite(STDERR, "Uso: php worker/teste-audio-evolution.php {instance} {telefone} [arquivo]\n");
	fwrite(STDERR, "Ex.: php worker/teste-audio-evolution.php escola_1 5515999999999\n");
	exit(1);
}

$api = EvolutionApiService::fromEnv();
if (!$api->isConfigured()) {
	fwrite(STDERR, "Evolution não configurada no .env\n");
	exit(1);
}

if ($file === '' || !is_file($file)) {
	// Gera WAV silencioso de 1s
	$file = sys_get_temp_dir().'/cti-teste-audio.wav';
	$sampleRate = 8000;
	$seconds = 1;
	$numSamples = $sampleRate * $seconds;
	$dataSize = $numSamples * 2;
	$header = pack(
		'A4VA4A4VvvVVvva4V',
		'RIFF',
		36 + $dataSize,
		'WAVE',
		'fmt ',
		16,
		1,
		1,
		$sampleRate,
		$sampleRate * 2,
		2,
		16,
		'data',
		$dataSize
	);
	$pcm = str_repeat("\x00\x00", $numSamples);
	file_put_contents($file, $header.$pcm);
	echo "Arquivo de teste gerado: {$file}\n";
}

echo "Instance: {$instance}\n";
echo "Number: {$number}\n";
echo "File: {$file} (".filesize($file)." bytes)\n";
echo "URL Evolution: ".$api->getBaseUrl()."\n\n";

$tests = [
	['label' => 'document multipart', 'fn' => function() use ($api, $instance, $number, $file) {
		return $api->sendMedia($instance, $number, $file, 'document', 'application/octet-stream', null, basename($file));
	}],
	['label' => 'audio multipart', 'fn' => function() use ($api, $instance, $number, $file) {
		return $api->sendMedia($instance, $number, $file, 'audio', 'audio/wav', null, basename($file));
	}],
	['label' => 'sendAudio helper', 'fn' => function() use ($api, $instance, $number, $file) {
		return $api->sendAudio($instance, $number, $file, 'audio/wav');
	}],
];

foreach ($tests as $t) {
	echo "=== {$t['label']} ===\n";
	$res = $t['fn']();
	echo 'HTTP: '.$api->getLastHttpCode()."\n";
	echo 'Erro: '.($api->getLastError() ?: '-')."\n";
	echo 'OK: '.(($res !== null && $api->getLastHttpCode() < 400) ? 'sim' : 'nao')."\n";
	if (is_array($res)) {
		echo 'Resposta: '.substr(json_encode($res, JSON_UNESCAPED_UNICODE), 0, 400)."\n";
	}
	echo "\n";
	if ($res !== null && $api->getLastHttpCode() < 400) {
		echo "Sucesso com: {$t['label']}\n";
		exit(0);
	}
}

echo "Todas as tentativas falharam.\n";
exit(2);
