<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsVitrineRepasse {

	public $id;
	public $id_fatura;
	public $id_escola_criadora;
	public $id_escola_assinante;
	public $id_curso;
	public $competencia;
	public $valor = 0;
	public $status = 'pendente';
	public $pago_em;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_vitrine_repasses'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public function cadastrar(): int {
		$this->id = (int)(new Database('lms_vitrine_repasses'))->insert([
			'id_fatura' => (int)$this->id_fatura,
			'id_escola_criadora' => (int)$this->id_escola_criadora,
			'id_escola_assinante' => (int)$this->id_escola_assinante,
			'id_curso' => (int)$this->id_curso,
			'competencia' => $this->competencia,
			'valor' => round((float)$this->valor, 2),
			'status' => $this->status === 'pago' ? 'pago' : 'pendente',
			'pago_em' => $this->pago_em ?: null,
		]);
		return $this->id;
	}

	public static function gerarDeFaturaPaga(int $idFatura, string $competencia): void {
		if (!self::tabelaExiste() || !SaasFaturaItem::tabelaExiste()) {
			return;
		}
		$exist = (new Database('lms_vitrine_repasses'))->select('id_fatura = '.(int)$idFatura, null, 1)->fetch();
		if ($exist) {
			return;
		}
		foreach (SaasFaturaItem::listByFatura($idFatura) as $item) {
			if (($item['tipo'] ?? '') !== 'licenca_curso') {
				continue;
			}
			$valor = (float)($item['valor'] ?? 0);
			if ($valor <= 0 || empty($item['id_escola_criadora']) || empty($item['id_curso'])) {
				continue;
			}
			$r = new self();
			$r->id_fatura = $idFatura;
			$r->id_escola_criadora = (int)$item['id_escola_criadora'];
			$r->id_escola_assinante = 0; // preenchido abaixo se possível
			$r->id_curso = (int)$item['id_curso'];
			$r->competencia = $competencia;
			$r->valor = $valor;
			$r->status = 'pendente';
			if (!empty($item['id_vitrine_assinatura'])) {
				$ass = LmsVitrineAssinatura::getById((int)$item['id_vitrine_assinatura']);
				if ($ass) {
					$r->id_escola_assinante = (int)$ass->id_escola_assinante;
				}
			}
			$r->cadastrar();
		}
	}

	public static function marcarPago(int $id): bool {
		(new Database('lms_vitrine_repasses'))->update('id = '.(int)$id, [
			'status' => 'pago',
			'pago_em' => date('Y-m-d H:i:s'),
		]);
		return true;
	}
}
