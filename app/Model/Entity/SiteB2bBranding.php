<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class SiteB2bBranding {

	public $id = 1;
	public $hero_titulo = '';
	public $hero_subtitulo = '';
	public $hero_cta_texto = 'Solicitar demonstração';
	public $hero_cta_link = '/contato';
	public $texto_institucional = '';
	public $logo;
	public $hero_image;
	public $telefone = '';
	public $email = '';
	public $whatsapp = '';
	public $link_alunos;
	public $stat_escolas = '10+';
	public $stat_anos = '15+';
	public $stat_modulos = '30+';
	public $catalogo_cti_em_breve = 1;
	public $meta_title = '';
	public $meta_description;
	public $redes_sociais_json;
	public $updated_at;

	public static function tabelasExistem(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'site_b2b_branding'");
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
		$row = (new Database('site_b2b_branding'))
			->select('id = 1', null, '1')
			->fetchObject(self::class);
		if ($row instanceof self) {
			return $row;
		}
		(new Database('site_b2b_branding'))->insert(['id' => 1]);
		return $ob;
	}

	public function salvar(): bool {
		if (!self::tabelasExistem()) {
			return false;
		}
		$db = new Database('site_b2b_branding');
		$existe = $db->select('id = 1', null, '1')->fetch(PDO::FETCH_ASSOC);
		$dados = [
			'hero_titulo'           => trim((string)$this->hero_titulo) !== '' ? trim((string)$this->hero_titulo) : 'Ecossistema completo para escolas profissionalizantes',
			'hero_subtitulo'        => $this->hero_subtitulo !== null && trim((string)$this->hero_subtitulo) !== '' ? trim((string)$this->hero_subtitulo) : null,
			'hero_cta_texto'        => trim((string)$this->hero_cta_texto) !== '' ? trim((string)$this->hero_cta_texto) : 'Solicitar demonstração',
			'hero_cta_link'         => trim((string)$this->hero_cta_link) !== '' ? trim((string)$this->hero_cta_link) : '/contato',
			'texto_institucional'   => $this->texto_institucional !== null && trim((string)$this->texto_institucional) !== '' ? trim((string)$this->texto_institucional) : null,
			'logo'                  => $this->logo !== null && $this->logo !== '' ? (string)$this->logo : null,
			'hero_image'            => $this->hero_image !== null && $this->hero_image !== '' ? (string)$this->hero_image : null,
			'telefone'              => $this->telefone !== null && trim((string)$this->telefone) !== '' ? trim((string)$this->telefone) : null,
			'email'                 => $this->email !== null && trim((string)$this->email) !== '' ? trim((string)$this->email) : null,
			'whatsapp'              => $this->whatsapp !== null && trim((string)$this->whatsapp) !== '' ? preg_replace('/\D/', '', trim((string)$this->whatsapp)) : null,
			'link_alunos'           => $this->link_alunos !== null && trim((string)$this->link_alunos) !== '' ? trim((string)$this->link_alunos) : null,
			'stat_escolas'          => trim((string)($this->stat_escolas ?? '10+')),
			'stat_anos'             => trim((string)($this->stat_anos ?? '15+')),
			'stat_modulos'          => trim((string)($this->stat_modulos ?? '30+')),
			'catalogo_cti_em_breve' => !empty($this->catalogo_cti_em_breve) ? 1 : 0,
			'meta_title'            => $this->meta_title !== null && trim((string)$this->meta_title) !== '' ? trim((string)$this->meta_title) : null,
			'meta_description'      => $this->meta_description !== null && trim((string)$this->meta_description) !== '' ? trim((string)$this->meta_description) : null,
			'redes_sociais_json'    => $this->redes_sociais_json !== null && trim((string)$this->redes_sociais_json) !== '' ? (string)$this->redes_sociais_json : null,
		];
		if ($existe) {
			return $db->update('id = 1', $dados);
		}
		$dados['id'] = 1;
		return (bool)$db->insert($dados);
	}
}
