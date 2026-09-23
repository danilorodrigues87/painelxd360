<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsAulaCena extends LmsBase {

	public $id;
	public $id_aula;
	public $id_admin;
	public $ordem = 0;
	public $media_kind = 'image';
	public $media_url = '';
	public $media_bunny_video_id;
	public $auto_advance = 0;
	public $instrucao;
	public $ocultar_instrucao = 0;
	public $auto_narracao;
	public $delay_revelar_ms;
	public $duracao_ms;
	public $tone = 'light';
	public $interacao;
	public $narracao_url;
	public $criado_em;
	public $atualizado_em;

	protected static function table(): string {
		return 'lms_aula_cenas';
	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_aula_cenas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaBunnyVideoId(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM lms_aula_cenas LIKE 'media_bunny_video_id'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaOcultarInstrucao(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM lms_aula_cenas LIKE 'ocultar_instrucao'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaAutoNarracao(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM lms_aula_cenas LIKE 'auto_narracao'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaDelayRevelar(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM lms_aula_cenas LIKE 'delay_revelar_ms'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function temColunaDuracaoMs(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW COLUMNS FROM lms_aula_cenas LIKE 'duracao_ms'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return list<self> */
	public static function listByAula(int $idAula, int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = self::get(
			'id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin,
			'ordem ASC, id ASC'
		);
		$rows = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	/**
	 * Substitui todas as cenas da aula (delete + insert) em transação quando possível.
	 *
	 * @param list<array<string,mixed>> $scenes
	 */
	public static function replaceAllForAula(int $idAula, int $idAdmin, array $scenes): void {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela lms_aula_cenas não existe. Execute database/lms_aulas_interativas.sql.');
		}

		$db = new Database(self::table());
		$useTx = true;
		try {
			$db->execute('START TRANSACTION');
		} catch (\Throwable $e) {
			$useTx = false;
		}

		try {
			$db->delete('id_aula = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin);

			$ordem = 0;
			foreach ($scenes as $scene) {
				if (!is_array($scene)) {
					continue;
				}
				$id = trim((string)($scene['id'] ?? ''));
				if ($id === '' || strlen($id) > 36) {
					$id = self::uuid();
				}
				$mediaKind = (string)($scene['media_kind'] ?? 'image');
				if ($mediaKind !== 'video') {
					$mediaKind = 'image';
				}
				$tone = (string)($scene['tone'] ?? 'light');
				if ($tone !== 'dark') {
					$tone = 'light';
				}
				$interacao = $scene['interacao'] ?? [];
				if (is_string($interacao)) {
					$decoded = json_decode($interacao, true);
					$interacao = is_array($decoded) ? $decoded : [];
				}
				if (!is_array($interacao)) {
					$interacao = [];
				}

				$row = [
					'id' => $id,
					'id_aula' => (int)$idAula,
					'id_admin' => (int)$idAdmin,
					'ordem' => $ordem,
					'media_kind' => $mediaKind,
					'media_url' => (string)($scene['media_url'] ?? ''),
					'auto_advance' => !empty($scene['auto_advance']) ? 1 : 0,
					'instrucao' => $scene['instrucao'] ?? null,
					'tone' => $tone,
					'interacao' => json_encode($interacao, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
					'narracao_url' => (static function ($v) {
						if ($v === null) {
							return null;
						}
						$t = trim((string)$v);
						return $t !== '' ? $t : null;
					})($scene['narracao_url'] ?? null),
				];
				if (self::temColunaOcultarInstrucao()) {
					$row['ocultar_instrucao'] = !empty($scene['ocultar_instrucao']) ? 1 : 0;
				}
				if (self::temColunaAutoNarracao()) {
					if (array_key_exists('auto_narracao', $scene)) {
						if ($scene['auto_narracao'] === null || $scene['auto_narracao'] === '') {
							$row['auto_narracao'] = null;
						} else {
							$row['auto_narracao'] = !empty($scene['auto_narracao']) ? 1 : 0;
						}
					}
				}
				if (self::temColunaDelayRevelar()) {
					if (array_key_exists('delay_revelar_ms', $scene)) {
						if ($scene['delay_revelar_ms'] === null || $scene['delay_revelar_ms'] === '') {
							$row['delay_revelar_ms'] = null;
						} else {
							$ms = (int)$scene['delay_revelar_ms'];
							$row['delay_revelar_ms'] = max(0, min(60000, $ms));
						}
					}
				}
				if (self::temColunaDuracaoMs()) {
					if (array_key_exists('duracao_ms', $scene)) {
						if ($scene['duracao_ms'] === null || $scene['duracao_ms'] === '') {
							$row['duracao_ms'] = null;
						} else {
							$ms = (int)$scene['duracao_ms'];
							$row['duracao_ms'] = max(0, min(120000, $ms));
						}
					}
				}
				if (self::temColunaBunnyVideoId()) {
					$vid = trim((string)($scene['media_bunny_video_id'] ?? ''));
					$row['media_bunny_video_id'] = $vid !== '' ? $vid : null;
				}
				$db->insert($row);
				$ordem++;
			}

			if ($useTx) {
				$db->execute('COMMIT');
			}
		} catch (\Throwable $e) {
			if ($useTx) {
				try {
					$db->execute('ROLLBACK');
				} catch (\Throwable $ignored) {
				}
			}
			throw $e;
		}
	}

	private static function uuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
