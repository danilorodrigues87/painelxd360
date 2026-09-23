<?php

namespace App\Common\Helpers;

use App\Model\Entity\ClientesAssinantes;

/** Tenant resolvido pelo host da requisição (subdomínio ou domínio custom). */
class TenantContext {

	private static bool $booted = false;
	private static string $mode = 'platform';
	private static ?int $idAdmin = null;
	private static ?string $slug = null;
	private static ?ClientesAssinantes $cliente = null;

	public static function boot(
		string $mode,
		?ClientesAssinantes $cliente = null
	): void {
		self::$booted = true;
		self::$mode = $mode;
		self::$cliente = $cliente;
		if ($cliente instanceof ClientesAssinantes) {
			self::$idAdmin = (int)$cliente->id;
			self::$slug = trim((string)($cliente->slug ?? '')) ?: null;
		} else {
			self::$idAdmin = null;
			self::$slug = null;
		}
	}

	public static function isBooted(): bool {
		return self::$booted;
	}

	public static function isTenantHost(): bool {
		return self::$mode !== 'platform';
	}

	public static function mode(): string {
		return self::$mode;
	}

	public static function hasTenant(): bool {
		return self::$cliente instanceof ClientesAssinantes;
	}

	public static function getCliente(): ?ClientesAssinantes {
		return self::$cliente;
	}

	public static function getIdAdmin(): ?int {
		return self::$idAdmin;
	}

	public static function getSlug(): ?string {
		return self::$slug;
	}
}
