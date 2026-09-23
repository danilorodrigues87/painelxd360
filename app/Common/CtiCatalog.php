<?php

namespace App\Common;

use App\Common\Environment;
use App\Controller\Admin\TermosDeUso;
use App\Model\Entity\ClientesAssinantes;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsEscolaCursoCti;
use App\Model\Entity\PlanosCurso;
use App\Model\Entity\User as EntityUser;

/**
 * Tenant e helpers do catálogo EAD CTI (cursos criados pelo Master).
 */
class CtiCatalog {

	public const ORIGEM_CTI = 'cti';
	public const ORIGEM_ESCOLA = 'escola';

	private const DIRETOR_EMAIL = 'catalogo.cti@ctieducacional.internal';

	private static function emailDiretorCatalogo(int $idAdmin): string {
		return 'catalogo.cti.'.(int)$idAdmin.'@ctieducacional.internal';
	}

	/** E-mail livre para o diretor do tenant catálogo (evita UNIQUE global). */
	private static function resolverEmailDiretorCatalogo(int $idAdmin): string {
		foreach ([self::DIRETOR_EMAIL, self::emailDiretorCatalogo($idAdmin)] as $email) {
			$u = EntityUser::getUserByEmail($email);
			if (!$u instanceof EntityUser) {
				return $email;
			}
			if ((int)($u->id_admin ?? 0) === (int)$idAdmin) {
				return $email;
			}
		}
		return self::emailDiretorCatalogo($idAdmin);
	}

	private static function buscarDiretorCatalogo(int $idAdmin): ?EntityUser {
		$diretor = EntityUser::getUser(
			'id_admin = '.(int)$idAdmin.' AND nivel = "Diretor"',
			'id ASC',
			1
		)->fetchObject(EntityUser::class);
		if ($diretor instanceof EntityUser) {
			return $diretor;
		}
		foreach ([self::DIRETOR_EMAIL, self::emailDiretorCatalogo($idAdmin)] as $email) {
			$porEmail = EntityUser::getUserByEmail($email);
			if (!$porEmail instanceof EntityUser) {
				continue;
			}
			if ((int)($porEmail->id_admin ?? 0) === (int)$idAdmin) {
				if ((string)($porEmail->nivel ?? '') !== 'Diretor') {
					self::atualizarCamposUsuario((int)$porEmail->id, [
						'nivel' => 'Diretor',
						'ativo' => 's',
					]);
					$porEmail->nivel = 'Diretor';
				}
				return $porEmail;
			}
			if ((int)($porEmail->id_admin ?? 0) <= 0 || (string)($porEmail->nivel ?? '') === 'Diretor') {
				self::atualizarCamposUsuario((int)$porEmail->id, [
					'id_admin' => $idAdmin,
					'nivel' => 'Diretor',
					'ativo' => 's',
				]);
				return EntityUser::getUserById((int)$porEmail->id);
			}
		}
		return null;
	}

	private static function preencherNovoDiretorCatalogo(EntityUser $diretor, int $idAdmin, string $email): void {
		$diretor->nome = 'CTI Catálogo';
		$diretor->email = $email;
		$diretor->nivel = 'Diretor';
		$diretor->senha = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
		$diretor->id_responsavel = 0;
		$diretor->whatsapp = '';
		$diretor->rg = '';
		$diretor->cpf = '';
		$diretor->nascimento = null;
		$diretor->endereco = '';
		$diretor->numero = '';
		$diretor->bairro = '';
		$diretor->uf = 0;
		$diretor->cidade = 0;
		$diretor->ativo = 's';
		$diretor->termos_uso = 1;
		if (EntityUser::temColunasTermosVersao()) {
			$diretor->termos_versao = TermosDeUso::VERSAO;
		}
		$diretor->acesso = json_encode(\App\Common\SystemModules::getPermissions(), JSON_UNESCAPED_UNICODE);
		$diretor->id_admin = $idAdmin;
	}

	public static function tabelasExistem(): bool {
		return PlanosCurso::tabelaExiste()
			&& LmsEscolaCursoCti::tabelaExiste()
			&& LmsCurso::temColunaOrigem();
	}

	public static function idAdmin(): int {
		static $cached = null;
		if ($cached !== null && $cached > 0) {
			return $cached;
		}

		$fromEnv = (int)Environment::get('CTI_CATALOG_ID', 0);
		if ($fromEnv > 0) {
			$cached = $fromEnv;
			return $cached;
		}

		if (ClientesAssinantes::temColunaCatalogoCti()) {
			$row = ClientesAssinantes::getEscolas('catalogo_cti = 1', null, 1, 'id')
				->fetch(\PDO::FETCH_ASSOC);
			if (!empty($row['id'])) {
				$cached = (int)$row['id'];
				return $cached;
			}
		}

		$escola = self::garantirEscolaCatalogo();
		$cached = $escola ? (int)$escola->id : 0;
		return $cached;
	}

	public static function garantirEscolaCatalogo(): ?ClientesAssinantes {
		if (!ClientesAssinantes::temColunaCatalogoCti()) {
			return null;
		}

		$row = ClientesAssinantes::getEscolas('catalogo_cti = 1', null, 1)
			->fetchObject(ClientesAssinantes::class);
		if ($row instanceof ClientesAssinantes) {
			return $row;
		}

		$ob = new ClientesAssinantes();
		$ob->nome = 'CTI Catálogo EAD';
		$ob->email = self::DIRETOR_EMAIL;
		$ob->telefone = '';
		$ob->cpf_cnpj = '';
		$ob->ativo = 's';
		$ob->modulos_liberados = null;
		$ob->catalogo_cti = 1;
		$ob->id_admin = 0;
		$ob->cadastrar();

		if ((int)$ob->id <= 0) {
			return null;
		}

		return ClientesAssinantes::getEscolaById((int)$ob->id);
	}

	public static function garantirDiretorCatalogo(int $idAdmin): ?EntityUser {
		$diretor = self::buscarDiretorCatalogo($idAdmin);

		if ($diretor instanceof EntityUser) {
			if ($diretor->ativo !== 's') {
				self::atualizarCamposUsuario((int)$diretor->id, ['ativo' => 's']);
				$diretor->ativo = 's';
			}
			self::garantirTermosDiretorCatalogo($diretor);
			return $diretor;
		}

		$email = self::resolverEmailDiretorCatalogo($idAdmin);
		$diretor = new EntityUser();
		self::preencherNovoDiretorCatalogo($diretor, $idAdmin, $email);

		try {
			$diretor->cadastrar();
		} catch (\Throwable $e) {
			$emailAlt = self::emailDiretorCatalogo($idAdmin);
			if ($email !== $emailAlt) {
				self::preencherNovoDiretorCatalogo($diretor, $idAdmin, $emailAlt);
				try {
					$diretor->cadastrar();
				} catch (\Throwable $e2) {
					return null;
				}
			} else {
				return null;
			}
		}

		$criado = (int)$diretor->id > 0
			? EntityUser::getUserById((int)$diretor->id)
			: null;
		if ($criado instanceof EntityUser) {
			self::garantirTermosDiretorCatalogo($criado);
		}
		return $criado instanceof EntityUser ? $criado : null;
	}

	/** Atualização parcial — evita User::atualizar() exigir endereço/nascimento. */
	private static function atualizarCamposUsuario(int $id, array $campos): void {
		if ($id <= 0 || empty($campos)) {
			return;
		}
		(new \App\Model\Db\Database('usuarios'))->update('id = '.$id, $campos);
	}

	/** Diretor sistema do catálogo: termos vigentes para não bloquear o editor no impersonate. */
	private static function garantirTermosDiretorCatalogo(EntityUser $diretor): void {
		$precisa = (int)($diretor->termos_uso ?? 0) !== 1;
		if (EntityUser::temColunasTermosVersao()) {
			$versao = trim((string)($diretor->termos_versao ?? ''));
			if ($versao !== TermosDeUso::VERSAO) {
				$precisa = true;
				$diretor->termos_versao = TermosDeUso::VERSAO;
			}
		}
		if (!$precisa) {
			return;
		}
		$diretor->termos_uso = 1;
		$diretor->termoAceito();
	}

	public static function isCursoCti(LmsCurso $curso): bool {
		if (!LmsCurso::temColunaOrigem()) {
			return false;
		}
		return (string)($curso->origem ?? self::ORIGEM_ESCOLA) === self::ORIGEM_CTI;
	}

	public static function isEscolaCatalogo(int $idAdmin): bool {
		$catId = self::idAdmin();
		return $catId > 0 && $catId === $idAdmin;
	}
}
