<?php

namespace App\Common\Helpers;

use App\Model\Entity\EscolaIntegracoes;
use App\Model\Entity\MetaWebhookLog;

/**
 * Log de diagnóstico: todo POST /webhook/meta (arquivo + banco).
 */
class MetaWebhookDebug {

	public const CODE_VERSION = '20260818e';

	public static function logInbound(?int $idAdmin, array $payload, string $rota = 'global'): void {
		$object = (string)($payload['object'] ?? '?');
		$hints = [];
		$commentHint = false;
		$messagingOnly = true;

		foreach (($payload['entry'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$parts = ['entry='.($entry['id'] ?? '?')];
			if (!empty($entry['messaging'])) {
				$parts[] = 'messaging×'.count($entry['messaging']);
			}
			if (!empty($entry['standby'])) {
				$parts[] = 'standby×'.count($entry['standby']);
			}
			if (isset($entry['field']) && (string)$entry['field'] !== '') {
				$parts[] = 'field='.(string)$entry['field'];
				$messagingOnly = false;
				if (in_array((string)$entry['field'], ['comments', 'live_comments', 'feed'], true)) {
					$commentHint = true;
				}
			}
			if (!empty($entry['changed_fields']) && is_array($entry['changed_fields'])) {
				$cf = array_map('strval', $entry['changed_fields']);
				$parts[] = 'changed_fields='.implode(',', $cf);
				$messagingOnly = false;
				foreach ($cf as $f) {
					if (in_array($f, ['comments', 'live_comments', 'feed'], true)) {
						$commentHint = true;
					}
				}
			}
			foreach (($entry['changes'] ?? []) as $change) {
				if (!is_array($change)) {
					continue;
				}
				$f = (string)($change['field'] ?? '?');
				$parts[] = 'change='.$f;
				$messagingOnly = false;
				if (in_array($f, ['comments', 'live_comments', 'feed'], true)) {
					$commentHint = true;
				}
			}
			if (empty($entry['messaging']) && empty($entry['standby'])
				&& !isset($entry['field']) && empty($entry['changes']) && empty($entry['changed_fields'])) {
				$parts[] = 'keys='.implode(',', array_keys($entry));
				$messagingOnly = false;
			}
			$hints[] = implode(' ', $parts);
		}

		if ($idAdmin === null || $idAdmin <= 0) {
			$idAdmin = self::resolverIdAdmin($payload);
		}

		$evento = $commentHint ? 'comentario?' : ($messagingOnly ? 'mensagem' : 'outro');
		$resumo = 'object='.$object.($hints ? ' · '.implode(' | ', $hints) : '');
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json)) {
			$json = '{}';
		}
		$detalhe = (!$messagingOnly || $commentHint) ? mb_substr($json, 0, 12000) : '';

		self::appendFile($rota.'/'.$evento, $resumo, $detalhe);

		if (MetaWebhookLog::tabelaExiste()) {
			MetaWebhookLog::registrar(
				$idAdmin && $idAdmin > 0 ? $idAdmin : null,
				'webhook_inbound',
				'ok',
				$rota.'/'.$evento,
				null,
				$resumo,
				$detalhe !== '' ? $detalhe : null
			);
		}
	}

	public static function logEvento(string $evento, string $resumo, string $detalhe = ''): void {
		self::appendFile($evento, $resumo, $detalhe);
		if (MetaWebhookLog::tabelaExiste()) {
			MetaWebhookLog::registrar(null, 'webhook_inbound', 'debug', $evento, null, $resumo, $detalhe !== '' ? $detalhe : null);
		}
	}

	/** @return array{code_version:string,db_ok:bool,file_ok:bool,file_path:string,file_size:int,recent_file:array,recent_db:array} */
	public static function status(?int $idAdmin = null): array {
		$path = self::filePath();
		$fileOk = is_file($path) && is_writable(dirname($path));
		if (!$fileOk) {
			@mkdir(dirname($path), 0755, true);
			$fileOk = @is_writable(dirname($path));
		}
		return [
			'code_version' => self::CODE_VERSION,
			'db_ok'          => MetaWebhookLog::tabelaExiste(),
			'file_ok'        => $fileOk,
			'file_path'      => $path,
			'file_size'      => is_file($path) ? (int)filesize($path) : 0,
			'recent_file'    => self::readRecentFile(35),
			'recent_db'      => MetaWebhookLog::tabelaExiste()
				? MetaWebhookLog::listRecentesTipos($idAdmin, ['comentario', 'webhook_inbound'], 35)
				: [],
		];
	}

	public static function filePath(): string {
		$root = dirname(__DIR__, 2);
		return $root.'/uploads/logs/meta_webhook_inbound.log';
	}

	/** @return array<int,array<string,mixed>> */
	public static function readRecentFile(int $limite = 30): array {
		$path = self::filePath();
		if (!is_file($path)) {
			return [];
		}
		$lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if (!is_array($lines)) {
			return [];
		}
		$lines = array_slice($lines, -max(1, $limite));
		$out = [];
		foreach (array_reverse($lines) as $line) {
			$decoded = json_decode($line, true);
			if (is_array($decoded)) {
				$out[] = $decoded;
			}
		}
		return $out;
	}

	private static function appendFile(string $evento, string $resumo, string $detalhe = ''): void {
		$path = self::filePath();
		$dir = dirname($path);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$row = [
			'tipo'           => 'webhook_inbound',
			'evento'         => $evento,
			'payload_resumo' => $resumo,
			'detalhe'        => $detalhe,
			'created_at'     => date('Y-m-d H:i:s'),
		];
		@file_put_contents($path, json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
	}

	private static function resolverIdAdmin(array $payload): ?int {
		foreach (($payload['entry'] ?? []) as $entry) {
			if (!is_array($entry)) {
				continue;
			}
			$entryId = (string)($entry['id'] ?? '');
			$object = (string)($payload['object'] ?? '');
			$pageId = $object === 'page' ? $entryId : '';
			$igId = $object === 'instagram' ? $entryId : '';
			$cfg = EscolaIntegracoes::getByMetaPageOrIg($pageId !== '' ? $pageId : null, $igId !== '' ? $igId : null);
			if ($cfg instanceof EscolaIntegracoes) {
				return (int)$cfg->id_admin;
			}
		}
		return null;
	}
}
