<?php

namespace App\Common\Helpers;

use App\Model\Entity\User as EntityUser;

class MasterStaffHelper {

	public const NIVEL_OPERADOR = 'Operador CTI';

	/** @return list<array{id:int,nome:string,email:string,cpf:string,is_super:bool}> */
	public static function listarParaSelect(): array {
		$out = [];
		$seen = [];

		$emailsSuper = MasterGateHelper::emailsPermitidos();
		foreach ($emailsSuper as $email) {
			$user = EntityUser::getUserByEmailNormalized($email);
			if ($user instanceof EntityUser && (int)$user->id > 0) {
				$seen[(int)$user->id] = true;
				$out[] = self::formatarResumo($user, true);
			}
		}

		$where = 'id_admin = 0 AND ativo = "s"';
		$results = EntityUser::getUser($where, 'nome ASC');
		while ($u = $results->fetchObject(EntityUser::class)) {
			$id = (int)$u->id;
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$out[] = self::formatarResumo($u, MasterGateHelper::isMasterEmail($u->email ?? ''));
		}

		usort($out, function ($a, $b) {
			return strcasecmp((string)$a['nome'], (string)$b['nome']);
		});

		return $out;
	}

	/** @return EntityUser[] */
	public static function listarTodos(): array {
		$out = [];
		foreach (self::listarParaSelect() as $row) {
			$u = EntityUser::getUserById((int)$row['id']);
			if ($u instanceof EntityUser) {
				$out[] = $u;
			}
		}
		return $out;
	}

	public static function isStaffMaster(?EntityUser $user): bool {
		if (!$user instanceof EntityUser) {
			return false;
		}
		if (MasterGateHelper::isMasterEmail($user->email ?? '')) {
			return true;
		}
		return (int)($user->id_admin ?? -1) === 0;
	}

	public static function pertenceStaffMaster(int $userId): bool {
		if ($userId <= 0) {
			return false;
		}
		$user = EntityUser::getUserById($userId);
		return self::isStaffMaster($user);
	}

	/** Garante id_admin=0 para e-mails em MASTER_EMAILS. */
	public static function bootstrapSuperAdmins(): void {
		foreach (MasterGateHelper::emailsPermitidos() as $email) {
			$user = EntityUser::getUserByEmailNormalized($email);
			if (!$user instanceof EntityUser) {
				continue;
			}
			if ((int)($user->id_admin ?? 0) !== 0) {
				$ob = new EntityUser();
				$ob->id = (int)$user->id;
				$ob->id_admin = 0;
				(new \App\Model\Db\Database('usuarios'))->update('id = '.(int)$user->id, ['id_admin' => 0]);
			}
		}
	}

	/** @return array{id:int,nome:string,email:string,cpf:string,is_super:bool} */
	private static function formatarResumo(EntityUser $u, bool $isSuper): array {
		$cpf = preg_replace('/\D+/', '', (string)($u->cpf ?? ''));
		$cpfFmt = strlen($cpf) === 11 ? SaasEmpresaXd360Helper::formatCpf($cpf) : '';
		return [
			'id'       => (int)$u->id,
			'nome'     => (string)$u->nome,
			'email'    => (string)$u->email,
			'cpf'      => $cpfFmt,
			'is_super' => $isSuper,
		];
	}
}
