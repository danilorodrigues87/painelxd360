<?php



namespace App\Model\Entity;



use App\Model\Db\Database;



class SocialBiblioteca {



	public $id;

	public $id_admin;

	public $titulo;

	public $tipo = 'image';

	public $formato;

	public $path_local;

	public $url_externa;

	public $mime;

	public $bytes;

	public $created_by;

	public $created_at;



	public static function tabelaExiste(): bool {

		static $ok = null;

		if ($ok !== null) {

			return $ok;

		}

		try {

			$st = (new Database())->execute("SHOW TABLES LIKE 'social_biblioteca'");

			$ok = $st && $st->rowCount() > 0;

		} catch (\Throwable $e) {

			$ok = false;

		}

		return $ok;

	}



	public static function colunaFormatoExiste(bool $forceRefresh = false): bool {

		static $ok = null;

		if ($forceRefresh) {
			$ok = null;
		}

		if ($ok !== null) {

			return $ok;

		}

		if (!self::tabelaExiste()) {

			$ok = false;

			return false;

		}

		try {

			$st = (new Database())->execute("SHOW COLUMNS FROM social_biblioteca LIKE 'formato'");

			$ok = $st && $st->rowCount() > 0;

		} catch (\Throwable $e) {

			$ok = false;

		}

		return $ok;

	}

	/** Cria coluna `formato` se a migration ainda não foi aplicada. */
	public static function garantirColunaFormato(): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		if (self::colunaFormatoExiste()) {
			return true;
		}
		try {
			(new Database())->execute(
				'ALTER TABLE `social_biblioteca`
				 ADD COLUMN `formato` VARCHAR(16) NULL DEFAULT NULL
				   COMMENT \'feed|story (imagens)\' AFTER `tipo`'
			);
			(new Database())->execute(
				'UPDATE `social_biblioteca`
				 SET `formato` = "feed"
				 WHERE `tipo` = "image" AND (`formato` IS NULL OR `formato` = "")'
			);
		} catch (\Throwable $e) {
			return false;
		}
		return self::colunaFormatoExiste(true);
	}



	public static function getById(int $id, int $idAdmin): ?self {

		$row = (new Database('social_biblioteca'))->select(

			'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin

		)->fetchObject(self::class);

		return $row instanceof self ? $row : null;

	}



	/** @return self[] */

	public static function listByAdmin(int $idAdmin, ?string $tipo = null, ?string $formato = null, int $limite = 100): array {

		$where = 'id_admin = '.(int)$idAdmin;

		if ($tipo === 'image' || $tipo === 'video') {

			$where .= ' AND tipo = "'.addslashes($tipo).'"';

		}

		if ($formato === 'feed' || $formato === 'story') {

			if (self::colunaFormatoExiste()) {

				if ($formato === 'feed') {

					$where .= ' AND (formato = "feed" OR formato IS NULL OR formato = "")';

				} else {

					$where .= ' AND formato = "story"';

				}

			}

		}

		$stmt = (new Database('social_biblioteca'))->select($where, 'id DESC', (int)$limite);

		$out = [];

		while ($r = $stmt->fetchObject(self::class)) {

			$out[] = $r;

		}

		return $out;

	}



	public function salvar(): int {

		$dados = [

			'id_admin' => (int)$this->id_admin,

			'titulo' => $this->titulo ?: null,

			'tipo' => in_array($this->tipo, ['image', 'video'], true) ? $this->tipo : 'image',

			'path_local' => $this->path_local ?: null,

			'url_externa' => $this->url_externa ?: null,

			'mime' => $this->mime ?: null,

			'bytes' => $this->bytes !== null ? (int)$this->bytes : null,

			'created_by' => $this->created_by ? (int)$this->created_by : null,

		];

		if (self::colunaFormatoExiste()) {

			$fmt = trim((string)($this->formato ?? ''));

			if ($dados['tipo'] === 'image' && in_array($fmt, ['feed', 'story'], true)) {

				$dados['formato'] = $fmt;

			} elseif ($dados['tipo'] === 'image') {

				$dados['formato'] = 'feed';

			} else {

				$dados['formato'] = null;

			}

		}

		$db = new Database('social_biblioteca');

		if (!empty($this->id)) {

			$db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);

			return (int)$this->id;

		}

		$this->id = (int)$db->insert($dados);

		return (int)$this->id;

	}



	public function excluir(): bool {

		if (!empty($this->path_local)) {

			\App\Common\Helpers\SocialMediaStorage::apagar((string)$this->path_local);

		}

		return (bool)(new Database('social_biblioteca'))->delete(

			'id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin

		);

	}



	public function urlPublica(): string {

		if (!empty($this->url_externa)) {

			return (string)$this->url_externa;

		}

		if (!empty($this->path_local)) {

			return \App\Common\Helpers\SocialMediaStorage::urlPublica((string)$this->path_local);

		}

		return '';

	}



	/** Path registrado na biblioteca desta escola. */
	public static function pathNaBiblioteca(int $idAdmin, string $path): bool {
		if ($path === '' || !self::tabelaExiste()) {
			return false;
		}
		$row = (new Database('social_biblioteca'))->select(
			'id_admin = '.(int)$idAdmin.' AND path_local = "'.addslashes($path).'"',
			null,
			1,
			'id'
		)->fetch(\PDO::FETCH_ASSOC);
		return !empty($row);
	}

	/** @deprecated Use pathNaBiblioteca() */
	public static function pathEmUso(int $idAdmin, string $path): bool {
		return self::pathNaBiblioteca($idAdmin, $path);
	}

}

