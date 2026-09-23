<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjPortalBranding {

	public $id = 1;
	public $nome_portal = 'Conecta Jovem';
	public $logo;
	public $hero_image;
	public $cores_json;
	public $texto_institucional;
	public $redes_sociais_json;
	public $updated_at;

	public static function tabelasExistem(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'cj_portal_branding'");
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
		$row = (new Database('cj_portal_branding'))
			->select('id = 1', null, '1')
			->fetchObject(self::class);
		if ($row instanceof self) {
			return $row;
		}
		(new Database('cj_portal_branding'))->insert([
			'id'          => 1,
			'nome_portal' => 'Conecta Jovem',
			'logo'        => null,
			'hero_image'  => null,
		]);
		return $ob;
	}

	public function salvar(): bool {
		if (!self::tabelasExistem()) {
			return false;
		}
		$db = new Database('cj_portal_branding');
		$existe = $db->select('id = 1', null, '1')->fetch(PDO::FETCH_ASSOC);
		$dados = [
			'nome_portal'         => trim((string)$this->nome_portal) !== '' ? trim((string)$this->nome_portal) : 'Conecta Jovem',
			'logo'                => $this->logo !== null && $this->logo !== '' ? (string)$this->logo : null,
			'hero_image'          => $this->hero_image !== null && $this->hero_image !== '' ? (string)$this->hero_image : null,
			'texto_institucional' => $this->texto_institucional !== null && trim((string)$this->texto_institucional) !== ''
				? trim((string)$this->texto_institucional)
				: null,
			'redes_sociais_json'  => $this->redes_sociais_json !== null && trim((string)$this->redes_sociais_json) !== ''
				? (string)$this->redes_sociais_json
				: null,
		];
		if ($existe) {
			return $db->update('id = 1', $dados);
		}
		$dados['id'] = 1;
		return (bool)$db->insert($dados);
	}
}
