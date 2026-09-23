<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjAnuncioConfig {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncio_config'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		if (!self::tabelaExiste()) {
			return self::defaults();
		}
		$row = (new Database('cj_anuncio_config'))->select('id = 1', null, '1')->fetch(PDO::FETCH_ASSOC);
		if (!is_array($row)) {
			return self::defaults();
		}
		$slots = json_decode($row['slots_habilitados'] ?? '[]', true);
		if (!is_array($slots)) {
			$slots = self::defaults()['slots_habilitados'];
		}
		return [
			'preco_minimo_mensal'     => (float)($row['preco_minimo_mensal'] ?? 99),
			'percentual_plataforma'   => (float)($row['percentual_plataforma'] ?? 20),
			'slots_habilitados'       => $slots,
			'max_anuncios_por_empresa'=> (int)($row['max_anuncios_por_empresa'] ?? 3),
			'requer_aprovacao_master' => !empty($row['requer_aprovacao_master']),
		];
	}

	/** @param array<string,mixed> $dados */
	public static function salvar(array $dados): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$payload = [];
		if (isset($dados['preco_minimo_mensal'])) {
			$payload['preco_minimo_mensal'] = max(0, (float)$dados['preco_minimo_mensal']);
		}
		if (isset($dados['percentual_plataforma'])) {
			$payload['percentual_plataforma'] = min(100, max(0, (float)$dados['percentual_plataforma']));
		}
		if (isset($dados['max_anuncios_por_empresa'])) {
			$payload['max_anuncios_por_empresa'] = max(1, (int)$dados['max_anuncios_por_empresa']);
		}
		if (array_key_exists('requer_aprovacao_master', $dados)) {
			$payload['requer_aprovacao_master'] = !empty($dados['requer_aprovacao_master']) ? 1 : 0;
		}
		if (isset($dados['slots_habilitados']) && is_array($dados['slots_habilitados'])) {
			$payload['slots_habilitados'] = json_encode(array_values($dados['slots_habilitados']), JSON_UNESCAPED_UNICODE);
		}
		if (empty($payload)) {
			return true;
		}
		$exists = (new Database('cj_anuncio_config'))->select('id = 1', null, '1')->fetch(PDO::FETCH_ASSOC);
		if ($exists) {
			return (new Database('cj_anuncio_config'))->update('id = 1', $payload);
		}
		$payload['id'] = 1;
		return (bool)(new Database('cj_anuncio_config'))->insert($payload);
	}

	/** @return array<string,mixed> */
	private static function defaults(): array {
		return [
			'preco_minimo_mensal'      => 99.0,
			'percentual_plataforma'    => 20.0,
			'slots_habilitados'        => ['footer_carousel', 'home_mid', 'vagas_sidebar', 'blog_sidebar', 'blog_artigo_fim'],
			'max_anuncios_por_empresa' => 3,
			'requer_aprovacao_master'  => true,
		];
	}
}
