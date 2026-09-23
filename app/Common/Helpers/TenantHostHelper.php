<?php

namespace App\Common\Helpers;

use App\Common\Environment;
use App\Model\Entity\ClientesAssinantes;

/** Resolve tenant a partir de HTTP_HOST (subdomínio XD360 ou domínio custom). */
class TenantHostHelper {

	public static function bootstrap(): void {
		if (TenantContext::isBooted()) {
			return;
		}

		$host = self::hostSemPorta();
		if ($host === '') {
			TenantContext::boot('platform');
			return;
		}

		if (self::isMasterHost($host)) {
			TenantContext::boot('platform');
			return;
		}

		$devSlug = trim((string)Environment::get('XD360_DEV_TENANT_SLUG', ''));
		if ($devSlug !== '' && self::isLocalHost($host)) {
			$cliente = ClientesAssinantes::getBySlug($devSlug);
			if ($cliente instanceof ClientesAssinantes) {
				TenantContext::boot('subdominio_dev', $cliente);
				return;
			}
		}

		$base = strtolower(trim((string)Environment::get('XD360_BASE_DOMAIN', '')));
		if ($base !== '' && str_ends_with($host, '.'.$base)) {
			$sub = substr($host, 0, -(strlen($base) + 1));
			$slug = self::extrairSlugSubdominio($sub);
			if ($slug !== null) {
				$cliente = ClientesAssinantes::getBySlug($slug);
				if ($cliente instanceof ClientesAssinantes) {
					TenantContext::boot('subdominio', $cliente);
					return;
				}
				TenantContext::boot('unknown_subdominio');
				return;
			}
		}

		$clienteCustom = ClientesAssinantes::getByDominioCustom($host);
		if ($clienteCustom instanceof ClientesAssinantes) {
			TenantContext::boot('dominio_custom', $clienteCustom);
			return;
		}

		$clientePainel = ClientesAssinantes::getByDominioPainel($host);
		if ($clientePainel instanceof ClientesAssinantes) {
			TenantContext::boot('dominio_painel', $clientePainel);
			return;
		}

		TenantContext::boot('platform');
	}

	public static function masterUrl(): string {
		$explicit = rtrim((string)Environment::get('XD360_MASTER_URL', ''), '/');
		if ($explicit !== '') {
			return $explicit;
		}
		return rtrim((string)(defined('URL') ? URL : ''), '/');
	}

	public static function urlSubdominioCliente(?ClientesAssinantes $cliente): ?string {
		if (!$cliente instanceof ClientesAssinantes) {
			return null;
		}
		$slug = trim((string)($cliente->slug ?? ''));
		if ($slug === '') {
			return null;
		}
		$base = trim((string)Environment::get('XD360_BASE_DOMAIN', ''));
		if ($base === '') {
			return null;
		}
		$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		return $scheme.'://'.$slug.'.'.$base;
	}

	private static function hostSemPorta(): string {
		$host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
		if ($host === '') {
			return '';
		}
		$host = preg_replace('/:\d+$/', '', $host) ?? $host;
		return $host;
	}

	private static function isLocalHost(string $host): bool {
		return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
	}

	private static function isMasterHost(string $host): bool {
		if (self::isLocalHost($host)) {
			return true;
		}
		$list = trim((string)Environment::get('XD360_MASTER_HOSTS', ''));
		if ($list === '') {
			$master = strtolower(trim((string)Environment::get('XD360_MASTER_HOST', '')));
			if ($master !== '' && $host === $master) {
				return true;
			}
			$base = strtolower(trim((string)Environment::get('XD360_BASE_DOMAIN', '')));
			if ($base !== '' && ($host === $base || $host === 'www.'.$base || $host === 'app.'.$base)) {
				return true;
			}
			return false;
		}
		$hosts = array_map('trim', explode(',', $list));
		return in_array($host, $hosts, true);
	}

	private static function extrairSlugSubdominio(string $sub): ?string {
		$sub = trim($sub, '.');
		if ($sub === '') {
			return null;
		}
		$parts = explode('.', $sub);
		if (count($parts) === 1) {
			return SlugHelper::sanitize($parts[0]) ?: null;
		}
		if ($parts[0] === 'painel' && count($parts) >= 2) {
			return SlugHelper::sanitize($parts[1]) ?: null;
		}
		return SlugHelper::sanitize($parts[0]) ?: null;
	}
}
