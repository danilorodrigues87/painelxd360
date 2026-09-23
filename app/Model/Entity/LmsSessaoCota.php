<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsSessaoCota {

	public $id, $id_admin, $id_aluno, $data, $aulas_ids, $atualizado_em;

	public static function getDia(int $idAdmin, int $idAluno, string $data) {
		return (new Database('lms_sessao_cota'))->select(
			'id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno.' AND data = "'.addslashes($data).'"',
			null, 1
		)->fetchObject(self::class);
	}

	/** @return int[] */
	public function idsAulas(): array {
		$raw = $this->aulas_ids;
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($raw)) {
			return [];
		}
		return array_values(array_unique(array_map('intval', $raw)));
	}

	public function salvarIds(array $ids): void {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$db = new Database('lms_sessao_cota');
		$payload = [
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'data' => $this->data,
			'aulas_ids' => json_encode($ids, JSON_UNESCAPED_UNICODE),
		];
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id, ['aulas_ids' => $payload['aulas_ids']]);
		} else {
			$this->id = $db->insert($payload);
		}
		$this->aulas_ids = $payload['aulas_ids'];
	}
}
