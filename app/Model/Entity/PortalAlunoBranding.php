<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class PortalAlunoBranding {

	public $id = 1;
	public $logo;
	public $login_hero;
	public $updated_at;

	public static function tabelasExistem(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'portal_aluno_branding'");
			$cache = (bool)$st->fetch(PDO::FETCH_NUM);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function get(): self {
		$ob = new self();
		if (!self::tabelasExistem()) {
			return $ob;
		}
		$row = (new Database('portal_aluno_branding'))
			->select('id = 1', null, '1')
			->fetchObject(self::class);
		if ($row instanceof self) {
			return $row;
		}
		(new Database('portal_aluno_branding'))->insert([
			'id' => 1,
			'logo' => null,
			'login_hero' => null,
		]);
		return $ob;
	}

	public function salvar(): bool {
		if (!self::tabelasExistem()) {
			return false;
		}
		$db = new Database('portal_aluno_branding');
		$existe = $db->select('id = 1', null, '1')->fetch(PDO::FETCH_ASSOC);
		$dados = [
			'logo' => $this->logo !== null && $this->logo !== '' ? (string)$this->logo : null,
			'login_hero' => $this->login_hero !== null && $this->login_hero !== '' ? (string)$this->login_hero : null,
		];
		if ($existe) {
			return $db->update('id = 1', $dados);
		}
		$dados['id'] = 1;
		return (bool)$db->insert($dados);
	}
}
