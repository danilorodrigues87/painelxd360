<?php

namespace App\Common\Helpers;

/** Gera slugs URL-safe para subdomínios XD360. */
class SlugHelper {

	public static function sanitize(string $raw): string {
		$s = mb_strtolower(trim($raw), 'UTF-8');
		$s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
		$s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
		$s = trim($s, '-');
		return mb_substr($s, 0, 80);
	}

	public static function fromNome(string $nome): string {
		return self::sanitize($nome);
	}

	/** @return string slug único sugerido */
	public static function unico(string $base, ?int $ignorarId = null): string {
		$slug = self::sanitize($base);
		if ($slug === '') {
			$slug = 'cliente';
		}
		$candidato = $slug;
		$n = 2;
		while (!self::disponivel($candidato, $ignorarId)) {
			$candidato = $slug.'-'.$n;
			$n++;
			if ($n > 500) {
				$candidato = $slug.'-'.bin2hex(random_bytes(3));
				break;
			}
		}
		return $candidato;
	}

	public static function disponivel(string $slug, ?int $ignorarId = null): bool {
		$slug = self::sanitize($slug);
		if ($slug === '') {
			return false;
		}
		if (in_array($slug, self::reservados(), true)) {
			return false;
		}
		if (!class_exists(\App\Model\Entity\ClientesAssinantes::class)) {
			return true;
		}
		$row = \App\Model\Entity\ClientesAssinantes::getBySlug($slug);
		if (!$row) {
			return true;
		}
		return $ignorarId !== null && (int)$row->id === $ignorarId;
	}

	/** @return string[] */
	public static function reservados(): array {
		return [
			'www', 'app', 'api', 'master', 'admin', 'painel', 'mail', 'smtp',
			'ftp', 'cdn', 'static', 'assets', 'blog', 'status', 'help', 'suporte',
			'xd360', 'login', 'cadastro', 'webhook', 'webhooks',
		];
	}
}
