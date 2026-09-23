<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Model\Entity\EstadoCidades;

class ConectEnderecoHelper {

	public static function limparNomeEstado(string $nome): string {
		$nome = trim($nome);
		if ($nome === '') {
			return '';
		}
		return trim((string)preg_replace('/^\d+\s*[-–—]?\s*/u', '', $nome));
	}

	public static function siglaEstado(array $row): string {
		$sigla = strtoupper(trim((string)($row['sigla'] ?? $row['uf'] ?? '')));
		return mb_substr($sigla, 0, 2);
	}

	/**
	 * @return array{cidadeNome:string,estadoId:?int,uf:string}
	 */
	public static function localPorCidadeId(int $cidadeId): array {
		if ($cidadeId <= 0) {
			return ['cidadeNome' => '', 'estadoId' => null, 'uf' => ''];
		}
		try {
			$stmt = (new Database('cidades'))->select('id = '.(int)$cidadeId, null, '1', 'id, nome, estados_id');
			$row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
			if (!is_array($row)) {
				return ['cidadeNome' => '', 'estadoId' => null, 'uf' => ''];
			}
			$estadoId = !empty($row['estados_id']) ? (int)$row['estados_id'] : null;
			$uf = '';
			if ($estadoId) {
				$est = EstadoCidades::getEstados('id = '.$estadoId)->fetch(\PDO::FETCH_ASSOC);
				if (is_array($est)) {
					$uf = self::siglaEstado($est);
				}
			}
			return [
				'cidadeNome' => (string)($row['nome'] ?? ''),
				'estadoId'   => $estadoId,
				'uf'         => $uf,
			];
		} catch (\Throwable $e) {
			return ['cidadeNome' => '', 'estadoId' => null, 'uf' => ''];
		}
	}

	/**
	 * @param array<string,mixed> $c
	 */
	public static function formatarEndereco(array $c): string {
		$rua = trim((string)($c['logradouro'] ?? ''));
		$num = trim((string)($c['numero'] ?? ''));
		$bairro = trim((string)($c['bairro'] ?? ''));
		$cidade = trim((string)($c['cidade_nome'] ?? ''));
		$uf = strtoupper(trim((string)($c['uf'] ?? '')));

		$linha1 = $rua;
		if ($rua !== '' && $num !== '') {
			$linha1 .= ', '.$num;
		} elseif ($num !== '') {
			$linha1 = $num;
		}

		$partes = array_filter([$linha1, $bairro], static fn ($p) => $p !== '');
		$cidadeUf = trim($cidade.($uf !== '' ? ' - '.$uf : ''));
		if ($cidadeUf !== '') {
			$partes[] = $cidadeUf;
		}

		return implode(' · ', $partes);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public static function decodeListaJson(?string $json, int $max = 20): array {
		if ($json === null || trim($json) === '') {
			return [];
		}
		$data = json_decode($json, true);
		if (!is_array($data)) {
			return [];
		}
		$out = [];
		foreach (array_slice($data, 0, $max) as $item) {
			if (is_array($item)) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param list<array<string,mixed>>|mixed $lista
	 */
	public static function encodeListaJson($lista, int $max = 20): ?string {
		if (!is_array($lista) || $lista === []) {
			return null;
		}
		$limpa = [];
		foreach (array_slice($lista, 0, $max) as $item) {
			if (is_array($item)) {
				$limpa[] = $item;
			}
		}
		if ($limpa === []) {
			return null;
		}
		return json_encode($limpa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param list<array<string,mixed>>|mixed $raw
	 * @return list<array<string,mixed>>
	 */
	public static function sanitizarExperiencias($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach (array_slice($raw, 0, 15) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$empresa = mb_substr(trim((string)($item['empresa'] ?? '')), 0, 191);
			$cargo = mb_substr(trim((string)($item['cargo'] ?? '')), 0, 191);
			if ($empresa === '' || $cargo === '') {
				continue;
			}
			$atual = !empty($item['atual']);
			$out[] = [
				'id'        => mb_substr(trim((string)($item['id'] ?? uniqid('exp', true))), 0, 40),
				'empresa'   => $empresa,
				'cargo'     => $cargo,
				'inicio'    => self::normalizarMesAno((string)($item['inicio'] ?? '')),
				'fim'       => $atual ? null : self::normalizarMesAno((string)($item['fim'] ?? '')),
				'atual'     => $atual,
				'descricao' => mb_substr(trim((string)($item['descricao'] ?? '')), 0, 2000),
			];
		}
		return $out;
	}

	/**
	 * @param list<array<string,mixed>>|mixed $raw
	 * @return list<array<string,mixed>>
	 */
	public static function sanitizarFormacaoAcademica($raw): array {
		if (!is_array($raw)) {
			return [];
		}
		$tipos = ['graduacao', 'pos', 'tecnico', 'outro'];
		$out = [];
		foreach (array_slice($raw, 0, 15) as $item) {
			if (!is_array($item)) {
				continue;
			}
			$tipo = (string)($item['tipo'] ?? 'outro');
			if (!in_array($tipo, $tipos, true)) {
				$tipo = 'outro';
			}
			$curso = mb_substr(trim((string)($item['curso'] ?? $item['titulo'] ?? '')), 0, 191);
			$inst = mb_substr(trim((string)($item['instituicao'] ?? '')), 0, 191);
			if ($curso === '') {
				continue;
			}
			$ano = isset($item['anoConclusao']) ? (int)$item['anoConclusao'] : (int)($item['ano'] ?? 0);
			$out[] = [
				'id'           => mb_substr(trim((string)($item['id'] ?? uniqid('form', true))), 0, 40),
				'tipo'         => $tipo,
				'curso'        => $curso,
				'instituicao'  => $inst,
				'anoConclusao' => $ano > 1900 && $ano <= 2100 ? $ano : null,
			];
		}
		return $out;
	}

	private static function normalizarMesAno(string $valor): ?string {
		$valor = trim($valor);
		if ($valor === '') {
			return null;
		}
		if (preg_match('/^(\d{4})-(\d{2})$/', $valor, $m)) {
			return $m[1].'-'.$m[2];
		}
		if (preg_match('/^(\d{2})\/(\d{4})$/', $valor, $m)) {
			return $m[2].'-'.$m[1];
		}
		if (preg_match('/^\d{4}$/', $valor)) {
			return $valor.'-01';
		}
		return null;
	}

	/**
	 * @return list<array{id:int,nome:string,uf:string}>
	 */
	public static function listarEstadosApi(): array {
		$rows = EstadoCidades::getEstados(null, 'nome ASC');
		$items = [];
		if ($rows) {
			while ($r = $rows->fetch(\PDO::FETCH_ASSOC)) {
				$items[] = [
					'id'   => (int)$r['id'],
					'nome' => self::limparNomeEstado((string)($r['nome'] ?? '')),
					'uf'   => self::siglaEstado($r),
				];
			}
		}
		return $items;
	}
}
