<?php

namespace App\Common\Helpers;

/**
 * Labels e regras de status/categoria dos chamados de suporte.
 */
class ChamadoHelper {

	public const CATEGORIAS = [
		'duvida'     => 'Dúvida',
		'erro'       => 'Erro / bug',
		'financeiro' => 'Financeiro',
		'acesso'     => 'Acesso / login',
		'sugestao'   => 'Sugestão',
		'outro'      => 'Outro',
	];

	public const STATUS = [
		'aberto'            => 'Aberto',
		'em_andamento'      => 'Em andamento',
		'aguardando_escola' => 'Aguardando escola',
		'resolvido'         => 'Resolvido',
		'fechado'           => 'Fechado',
	];

	public static function labelCategoria(string $slug): string {
		return self::CATEGORIAS[$slug] ?? $slug;
	}

	public static function labelStatus(string $slug): string {
		return self::STATUS[$slug] ?? $slug;
	}

	public static function categoriaValida(string $slug): bool {
		return isset(self::CATEGORIAS[$slug]);
	}

	public static function statusValido(string $slug): bool {
		return isset(self::STATUS[$slug]);
	}

	public static function escolaPodeResponder(string $status): bool {
		return !in_array($status, ['resolvido', 'fechado'], true);
	}

	public static function masterPodeResponder(string $status): bool {
		return !in_array($status, ['resolvido', 'fechado'], true);
	}

	/** @return list<array{slug:string,label:string}> */
	public static function categoriasLista(): array {
		$out = [];
		foreach (self::CATEGORIAS as $slug => $label) {
			$out[] = ['slug' => $slug, 'label' => $label];
		}
		return $out;
	}

	/** @return list<array{slug:string,label:string}> */
	public static function statusLista(): array {
		$out = [];
		foreach (self::STATUS as $slug => $label) {
			$out[] = ['slug' => $slug, 'label' => $label];
		}
		return $out;
	}
}
