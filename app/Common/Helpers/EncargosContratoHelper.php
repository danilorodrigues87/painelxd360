<?php

namespace App\Common\Helpers;

use App\Model\Entity\Caixa;
use App\Model\Entity\FinanceiroAcordo;
use App\Model\Entity\Matriculas;

/**
 * Simulação de multa/juros (atraso) e multa rescisória (cancelamento) conforme contrato da matrícula.
 */
class EncargosContratoHelper {

	public const DEFAULT_MULTA_ATRASO = 2.0;
	public const DEFAULT_JUROS_MES = 1.0;
	public const DEFAULT_MULTA_CANCEL = 10.0;
	public const DEFAULT_CARENCIA = 7;
	public const DEFAULT_MAX_PARCELAS_DESISTENCIA = 3;

	/** @return array{multa_atraso_pct:float,juros_mora_pct_mes:float,multa_cancelamento_pct:float,carencia_dias:int} */
	public static function defaults(): array {
		return [
			'multa_atraso_pct'       => self::DEFAULT_MULTA_ATRASO,
			'juros_mora_pct_mes'     => self::DEFAULT_JUROS_MES,
			'multa_cancelamento_pct' => self::DEFAULT_MULTA_CANCEL,
			'carencia_dias'          => self::DEFAULT_CARENCIA,
		];
	}

	/** @return array{multa_atraso_pct:float,juros_mora_pct_mes:float,multa_cancelamento_pct:float,carencia_dias:int} */
	public static function parametrosPorMatricula(int $idMatricula): array {
		if ($idMatricula <= 0) {
			return self::defaults();
		}
		$m = Matriculas::getMatriculaById($idMatricula);
		return self::parametrosFromMatriculaRow($m);
	}

	/** @param object|array|null $row */
	public static function parametrosFromMatriculaRow($row): array {
		$def = self::defaults();
		if (!$row) {
			return $def;
		}
		if (!Matriculas::temColunaEncargos()) {
			return $def;
		}
		$a = (array)$row;
		return [
			'multa_atraso_pct'       => self::normalizarPct($a['multa_atraso_pct'] ?? null, self::DEFAULT_MULTA_ATRASO),
			'juros_mora_pct_mes'     => self::normalizarPct($a['juros_mora_pct_mes'] ?? null, self::DEFAULT_JUROS_MES),
			'multa_cancelamento_pct' => self::normalizarPct($a['multa_cancelamento_pct'] ?? null, self::DEFAULT_MULTA_CANCEL),
			'carencia_dias'          => max(0, (int)($a['carencia_dias'] ?? self::DEFAULT_CARENCIA)),
		];
	}

	/**
	 * Normaliza valores vindos do formulário de matrícula.
	 * @return array{multa_atraso_pct:float,juros_mora_pct_mes:float,multa_cancelamento_pct:float,carencia_dias:int}
	 */
	public static function normalizarPostEncargos(array $post): array {
		return [
			'multa_atraso_pct'       => self::normalizarPct($post['multa_atraso_pct'] ?? null, self::DEFAULT_MULTA_ATRASO),
			'juros_mora_pct_mes'     => self::normalizarPct($post['juros_mora_pct_mes'] ?? null, self::DEFAULT_JUROS_MES),
			'multa_cancelamento_pct' => self::normalizarPct($post['multa_cancelamento_pct'] ?? null, self::DEFAULT_MULTA_CANCEL),
			'carencia_dias'          => max(0, (int)($post['carencia_dias'] ?? self::DEFAULT_CARENCIA)),
		];
	}

	private static function normalizarPct($val, float $fallback): float {
		$n = is_numeric($val) ? (float)$val : $fallback;
		return max(0, round($n, 2));
	}

	/**
	 * Juros proporcionais: 1% ao mês = 1/30 por dia sobre o valor da parcela (após carência).
	 *
	 * @return array{
	 *   valor_face:float,multa:float,juros:float,total_com_encargos:float,total_sem_encargos:float,
	 *   dias_atraso:int,dias_cobraveis:int,elegivel_encargos:bool,carencia_dias:int,
	 *   multa_atraso_pct:float,juros_mora_pct_mes:float
	 * }
	 */
	public static function calcularAtraso(
		float $valorFace,
		string $vencimento,
		array $params,
		?string $dataReferencia = null
	): array {
		$valorFace = round(max(0, $valorFace), 2);
		$dataReferencia = trim((string)($dataReferencia ?: date('Y-m-d')));
		$venc = trim($vencimento);
		$carencia = max(0, (int)($params['carencia_dias'] ?? self::DEFAULT_CARENCIA));
		$multaPct = (float)($params['multa_atraso_pct'] ?? self::DEFAULT_MULTA_ATRASO);
		$jurosPctMes = (float)($params['juros_mora_pct_mes'] ?? self::DEFAULT_JUROS_MES);

		$base = [
			'valor_face'           => $valorFace,
			'multa'                => 0.0,
			'juros'                => 0.0,
			'total_com_encargos'   => $valorFace,
			'total_sem_encargos'   => $valorFace,
			'dias_atraso'          => 0,
			'dias_cobraveis'       => 0,
			'elegivel_encargos'    => false,
			'carencia_dias'        => $carencia,
			'multa_atraso_pct'     => $multaPct,
			'juros_mora_pct_mes'   => $jurosPctMes,
		];

		if ($valorFace <= 0 || $venc === '' || $venc === '0000-00-00') {
			return $base;
		}

		$tsVenc = strtotime($venc);
		$tsRef = strtotime($dataReferencia);
		if ($tsVenc === false || $tsRef === false) {
			return $base;
		}

		$diasAtraso = (int)floor(($tsRef - $tsVenc) / 86400);
		$base['dias_atraso'] = max(0, $diasAtraso);
		$diasCobraveis = max(0, $diasAtraso - $carencia);
		$base['dias_cobraveis'] = $diasCobraveis;

		if ($diasCobraveis <= 0) {
			return $base;
		}

		$multa = round($valorFace * ($multaPct / 100), 2);
		$juros = round($valorFace * ($jurosPctMes / 100) * ($diasCobraveis / 30), 2);
		$total = round($valorFace + $multa + $juros, 2);

		$base['multa'] = $multa;
		$base['juros'] = $juros;
		$base['total_com_encargos'] = $total;
		$base['elegivel_encargos'] = ($multa + $juros) > 0;

		return $base;
	}

	public static function calcularAtrasoTitulo(Caixa $titulo, array $params, ?string $dataReferencia = null): array {
		$face = self::faceTituloMatricula($titulo);
		return self::calcularAtraso(
			$face,
			(string)($titulo->vencimento ?? ''),
			$params,
			$dataReferencia
		);
	}

	/** Valor face do título após desconto de pontualidade (quando elegível). */
	public static function faceTituloMatricula(Caixa $titulo): float {
		$idRef = (int)($titulo->id_ref ?? 0);
		if ($idRef <= 0 || (int)($titulo->id_acordo ?? 0) > 0) {
			return round((float)($titulo->valor ?? 0), 2);
		}
		$flagPont = 0;
		$mat = Matriculas::getMatriculaById($idRef);
		if ($mat) {
			$flagPont = (int)($mat->desconto_pontualidade ?? 0);
		}
		$pont = FinanceiroAlunoHelper::calcularPontualidade(
			(float)($titulo->valor ?? 0),
			(string)($titulo->vencimento ?? ''),
			$flagPont
		);
		return (float)$pont['valor_pagar'];
	}

	public static function tituloElegivelEncargos(Caixa $titulo): bool {
		if ((int)($titulo->id_acordo ?? 0) > 0) {
			return false;
		}
		$ref = trim((string)($titulo->referencia ?? ''));
		if ($ref === 'Multa rescisória') {
			return false;
		}
		return (int)($titulo->id_ref ?? 0) > 0;
	}

	/**
	 * Valor a pagar de um título (face + multa/juros opcionais).
	 * @return array{face:float,valor:float,enc:array,elegivel_encargos:bool}
	 */
	public static function resolverValoresTituloCaixa(Caixa $titulo, bool $cobrarEncargos, ?string $dataReferencia = null): array {
		$dataReferencia = trim((string)($dataReferencia ?: date('Y-m-d')));
		$face = self::faceTituloMatricula($titulo);
		$enc = [
			'valor_face' => $face,
			'multa' => 0.0,
			'juros' => 0.0,
			'total_com_encargos' => $face,
			'total_sem_encargos' => $face,
			'elegivel_encargos' => false,
		];
		if (self::tituloElegivelEncargos($titulo)) {
			$idRef = (int)($titulo->id_ref ?? 0);
			$params = self::parametrosPorMatricula($idRef);
			$enc = self::calcularAtraso($face, (string)($titulo->vencimento ?? ''), $params, $dataReferencia);
		}
		$valor = ($cobrarEncargos && !empty($enc['elegivel_encargos']))
			? (float)$enc['total_com_encargos']
			: $face;
		return [
			'face' => round($face, 2),
			'valor' => round($valor, 2),
			'enc' => $enc,
			'elegivel_encargos' => !empty($enc['elegivel_encargos']),
		];
	}

	/**
	 * Dívida consolidada do aluno (títulos em aberto com encargos até a data de referência).
	 * @return array{ok:bool,message?:string,titulos?:array,total_face?:float,total_multa?:float,total_juros?:float,total_com_encargos?:float,total_vencidos_com_encargos?:float,qtd_abertos?:int,qtd_vencidos?:int,data_referencia?:string}
	 */
	public static function calcularDividaAluno(int $idAdmin, int $idAluno, ?string $dataReferencia = null): array {
		if ($idAdmin <= 0 || $idAluno <= 0) {
			return ['ok' => false, 'message' => 'Parâmetros inválidos.'];
		}
		$aluno = \App\Model\Entity\User::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno não encontrado.'];
		}
		$dataReferencia = trim((string)($dataReferencia ?: date('Y-m-d')));
		$titulos = [];
		$totalFace = 0.0;
		$totalMulta = 0.0;
		$totalJuros = 0.0;
		$totalComEnc = 0.0;
		$totalVencidosComEnc = 0.0;
		$qtdVencidos = 0;

		$mats = Matriculas::getMatriculas(
			'id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno,
			'id DESC'
		);
		while ($m = $mats->fetchObject(Matriculas::class)) {
			$cx = Caixa::getCaixa(
				'id_admin = '.(int)$idAdmin
				.' AND id_ref = '.(int)$m->id
				.' AND tipo_transacao = "Entrada"'
				.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status'),
				'vencimento ASC, id ASC'
			);
			while ($c = $cx->fetchObject(Caixa::class)) {
				$vals = self::resolverValoresTituloCaixa($c, true, $dataReferencia);
				$enc = $vals['enc'];
				$titulos[] = [
					'id' => (int)$c->id,
					'descricao' => (string)($c->descricao ?? ''),
					'vencimento' => (string)($c->vencimento ?? ''),
					'origem' => 'matricula',
					'origem_id' => (int)$m->id,
					'valor_face' => $vals['face'],
					'multa' => (float)($enc['multa'] ?? 0),
					'juros' => (float)($enc['juros'] ?? 0),
					'total_com_encargos' => $vals['valor'],
					'elegivel_encargos' => $vals['elegivel_encargos'],
				];
				$totalFace += $vals['face'];
				$totalMulta += (float)($enc['multa'] ?? 0);
				$totalJuros += (float)($enc['juros'] ?? 0);
				$totalComEnc += $vals['valor'];
				if (($c->vencimento ?? '') !== '' && (string)$c->vencimento < $dataReferencia) {
					$qtdVencidos++;
					$totalVencidosComEnc += $vals['valor'];
				}
			}
		}

		if (FinanceiroAcordo::tabelasExistem() && FinanceiroAcordo::caixaTemIdAcordo()) {
			foreach (FinanceiroAcordo::listByAluno($idAluno, $idAdmin) as $ac) {
				$cx = Caixa::getCaixa(
					'id_admin = '.(int)$idAdmin
					.' AND id_acordo = '.(int)$ac->id
					.' AND tipo_transacao = "Entrada"'
					.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status'),
					'vencimento ASC, id ASC'
				);
				while ($c = $cx->fetchObject(Caixa::class)) {
					$face = round((float)($c->valor ?? 0), 2);
					$titulos[] = [
						'id' => (int)$c->id,
						'descricao' => (string)($c->descricao ?? ''),
						'vencimento' => (string)($c->vencimento ?? ''),
						'origem' => 'acordo',
						'origem_id' => (int)$ac->id,
						'valor_face' => $face,
						'multa' => 0.0,
						'juros' => 0.0,
						'total_com_encargos' => $face,
						'elegivel_encargos' => false,
					];
					$totalFace += $face;
					$totalComEnc += $face;
					if (($c->vencimento ?? '') !== '' && (string)$c->vencimento < $dataReferencia) {
						$qtdVencidos++;
						$totalVencidosComEnc += $face;
					}
				}
			}
		}

		return [
			'ok' => true,
			'titulos' => $titulos,
			'total_face' => round($totalFace, 2),
			'total_multa' => round($totalMulta, 2),
			'total_juros' => round($totalJuros, 2),
			'total_com_encargos' => round($totalComEnc, 2),
			'total_vencidos_com_encargos' => round($totalVencidosComEnc, 2),
			'qtd_abertos' => count($titulos),
			'qtd_vencidos' => $qtdVencidos,
			'data_referencia' => $dataReferencia,
		];
	}

	/** Valor para campanhas de inadimplentes: ativo = só vencidos; só cancelado = dívida total em aberto. */
	public static function valorDebitoCampanhaInadimplentes(int $idAdmin, int $idAluno, array $div): float {
		if (empty($div['ok'])) {
			return 0.0;
		}
		if (self::alunoTemMatriculaAtiva($idAdmin, $idAluno)) {
			return (float)($div['total_vencidos_com_encargos'] ?? 0);
		}
		return (float)($div['total_com_encargos'] ?? 0);
	}

	private static function alunoTemMatriculaAtiva(int $idAdmin, int $idAluno): bool {
		if ($idAdmin <= 0 || $idAluno <= 0) {
			return false;
		}
		$rs = Matriculas::getMatriculas(
			'id_admin = '.(int)$idAdmin
			.' AND id_aluno = '.(int)$idAluno
			.' AND '.MatriculaStatusHelper::sqlAtiva('matriculas'),
			'id DESC',
			'1'
		);
		return (bool)$rs->fetchObject(Matriculas::class);
	}

	/**
	 * Bloco HTML para modais de pagamento (multa/juros opcionais).
	 */
	public static function htmlBlocoPagamento(array $calc): string {
		if (empty($calc['elegivel_encargos'])) {
			return '';
		}
		$face = NumeroHelper::moedaBr($calc['valor_face']);
		$multa = NumeroHelper::moedaBr($calc['multa']);
		$juros = NumeroHelper::moedaBr($calc['juros']);
		$total = NumeroHelper::moedaBr($calc['total_com_encargos']);
		$dias = (int)($calc['dias_cobraveis'] ?? 0);
		$carencia = (int)($calc['carencia_dias'] ?? 0);
		$multaPct = number_format((float)($calc['multa_atraso_pct'] ?? 0), 2, ',', '.');
		$jurosPct = number_format((float)($calc['juros_mora_pct_mes'] ?? 0), 2, ',', '.');

		return '
		<li class="list-group-item bg-light encargos-bloco">
			<div class="form-check mb-2">
				<input class="form-check-input" type="checkbox" id="cobrar_encargos" name="cobrar_encargos" value="1" checked>
				<label class="form-check-label fw-semibold" for="cobrar_encargos">Cobrar multa e juros de mora</label>
			</div>
			<input type="hidden" id="enc_valor_face" value="'.htmlspecialchars((string)$calc['valor_face'], ENT_QUOTES, 'UTF-8').'">
			<input type="hidden" id="enc_valor_multa" value="'.htmlspecialchars((string)$calc['multa'], ENT_QUOTES, 'UTF-8').'">
			<input type="hidden" id="enc_valor_juros" value="'.htmlspecialchars((string)$calc['juros'], ENT_QUOTES, 'UTF-8').'">
			<input type="hidden" id="enc_total_com_encargos" value="'.htmlspecialchars((string)$calc['total_com_encargos'], ENT_QUOTES, 'UTF-8').'">
			<p class="small text-muted mb-2">Atraso cobrável: '.$dias.' dia(s) (carência de '.$carencia.' dias após o vencimento).</p>
			<ul class="list-unstyled small mb-0">
				<li class="d-flex justify-content-between"><span>Valor da parcela</span><span>R$ '.$face.'</span></li>
				<li class="d-flex justify-content-between"><span>Multa ('.$multaPct.'%)</span><span>R$ '.$multa.'</span></li>
				<li class="d-flex justify-content-between"><span>Juros ('.$jurosPct.'% a.m. proporcional)</span><span>R$ '.$juros.'</span></li>
				<li class="d-flex justify-content-between fw-semibold border-top pt-1 mt-1"><span>Total com encargos</span><span id="enc_total_exib">R$ '.$total.'</span></li>
			</ul>
			<p class="small text-muted mt-2 mb-0">Desmarque para perdoar multa e juros (cobrar só o valor da parcela).</p>
		</li>';
	}

	/**
	 * @param int[]|null $parcelasCobrar IDs de títulos que permanecem em aberto; null = padrão (vencidas sim, futuras não).
	 * @return array{ok:bool,message?:string,params?:array,parcelas?:array,vencidas?:array,futuras?:array,
	 *   parcelas_cobrar?:int[],total_cobrar_face?:float,total_cobrar_com_encargos?:float,total_baixar_face?:float,
	 *   total_vencidas_face?:float,total_vencidas_com_encargos?:float,total_futuras_face?:float,
	 *   multa_rescisoria?:float,total_geral_com_encargos?:float,qtd_vencidas?:int,qtd_futuras?:int,qtd_cobrar?:int,qtd_baixar?:int}
	 */
	public static function simularCancelamento(int $idMatricula, int $idAdmin, ?array $parcelasCobrar = null): array {
		return self::simularAcertoFinanceiro(
			$idMatricula,
			$idAdmin,
			$parcelasCobrar,
			[MatriculaStatusHelper::STATUS_ANDAMENTO],
			'Só é possível simular cancelamento de matrícula em andamento.'
		);
	}

	public static function simularRegularizacaoFinanceira(int $idMatricula, int $idAdmin, ?array $parcelasCobrar = null): array {
		$res = self::simularAcertoFinanceiro(
			$idMatricula,
			$idAdmin,
			$parcelasCobrar,
			[MatriculaStatusHelper::STATUS_ENCERRADO],
			'Só é possível regularizar matrícula encerrada.'
		);
		if (!empty($res['ok'])) {
			$res['modo'] = 'regularizar';
			$res['tem_multa_rescisoria_aberta'] = self::temMultaRescisoriaAberta($idMatricula, $idAdmin);
			$res['max_parcelas_desistencia'] = self::DEFAULT_MAX_PARCELAS_DESISTENCIA;
		}
		return $res;
	}

	/** @return int[] IDs das vencidas mais antigas (até $max) para cobrança. */
	public static function parcelasCobrarPresetPoliticaDesistencia(array $parcelas, int $max = self::DEFAULT_MAX_PARCELAS_DESISTENCIA): array {
		$max = max(1, min(12, $max));
		$vencidas = [];
		foreach ($parcelas as $p) {
			if (($p['grupo'] ?? '') === 'vencida') {
				$vencidas[] = $p;
			}
		}
		usort($vencidas, static function ($a, $b) {
			return strcmp((string)($a['vencimento'] ?? ''), (string)($b['vencimento'] ?? ''));
		});
		$ids = [];
		foreach (array_slice($vencidas, 0, $max) as $p) {
			$id = (int)($p['id'] ?? 0);
			if ($id > 0) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public static function temMultaRescisoriaAberta(int $idMatricula, int $idAdmin): bool {
		if ($idMatricula <= 0 || $idAdmin <= 0) {
			return false;
		}
		$rs = Caixa::getCaixa(
			'id_admin = '.(int)$idAdmin
			.' AND id_ref = '.(int)$idMatricula
			.' AND tipo_transacao = "Entrada"'
			.' AND referencia = "Multa rescisória"'
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status'),
			'id ASC',
			'1'
		);
		return (bool)$rs->fetchObject(Caixa::class);
	}

	/**
	 * @param int[]|null $parcelasCobrar
	 * @param int[] $statusPermitidos
	 */
	private static function simularAcertoFinanceiro(
		int $idMatricula,
		int $idAdmin,
		?array $parcelasCobrar,
		array $statusPermitidos,
		string $msgStatusInvalido
	): array {
		if ($idMatricula <= 0 || $idAdmin <= 0) {
			return ['ok' => false, 'message' => 'Matrícula inválida.'];
		}
		if (!TenantHelper::pertenceMatricula($idMatricula, $idAdmin)) {
			return ['ok' => false, 'message' => 'Matrícula não encontrada.'];
		}
		$m = Matriculas::getMatriculaById($idMatricula);
		if (!$m || (int)$m->id_admin !== $idAdmin) {
			return ['ok' => false, 'message' => 'Matrícula não encontrada.'];
		}
		if (!in_array((int)($m->status ?? 0), $statusPermitidos, true)) {
			return ['ok' => false, 'message' => $msgStatusInvalido];
		}

		$params = self::parametrosPorMatricula($idMatricula);
		$hoje = date('Y-m-d');

		$where = 'id_admin = '.(int)$idAdmin
			.' AND id_ref = '.(int)$idMatricula
			.' AND tipo_transacao = "Entrada"'
			.' AND '.FinanceiroAlunoHelper::sqlTituloAberto('status');

		$rs = Caixa::getCaixa($where, 'vencimento ASC');
		$parcelas = [];
		$idsValidos = [];

		while ($c = $rs->fetchObject(Caixa::class)) {
			if (trim((string)($c->referencia ?? '')) === 'Multa rescisória') {
				continue;
			}
			$idCx = (int)$c->id;
			$idsValidos[$idCx] = true;
			$face = self::faceTituloMatricula($c);
			$venc = (string)($c->vencimento ?? '');
			$calc = self::calcularAtraso($face, $venc, $params, $hoje);
			$grupo = ($venc !== '' && $venc < $hoje) ? 'vencida' : 'futura';
			$parcelas[] = [
				'id'                 => $idCx,
				'descricao'          => (string)($c->descricao ?? ''),
				'vencimento'         => $venc,
				'vencimento_br'      => DateTimeHelper::databr($venc),
				'valor_face'         => round($face, 2),
				'multa'              => $calc['multa'],
				'juros'              => $calc['juros'],
				'total_com_encargos' => $calc['total_com_encargos'],
				'dias_cobraveis'     => $calc['dias_cobraveis'],
				'elegivel_encargos'  => $calc['elegivel_encargos'],
				'grupo'              => $grupo,
				'cobrar_default'     => $grupo === 'vencida',
			];
		}

		if (empty($parcelas)) {
			return ['ok' => false, 'message' => 'Nenhuma parcela em aberto nesta matrícula.'];
		}

		$cobrarMap = [];
		if ($parcelasCobrar === null) {
			foreach ($parcelas as $p) {
				if (!empty($p['cobrar_default'])) {
					$cobrarMap[(int)$p['id']] = true;
				}
			}
		} else {
			foreach ($parcelasCobrar as $id) {
				$id = (int)$id;
				if ($id > 0 && isset($idsValidos[$id])) {
					$cobrarMap[$id] = true;
				}
			}
		}

		$vencidas = [];
		$futuras = [];
		$totalVencFace = 0.0;
		$totalVencEnc = 0.0;
		$totalFutFace = 0.0;
		$totalCobrarFace = 0.0;
		$totalCobrarEnc = 0.0;
		$totalBaixarFace = 0.0;
		$totalMultaBase = 0.0;
		$qtdCobrar = 0;
		$qtdBaixar = 0;

		foreach ($parcelas as $p) {
			$idCx = (int)$p['id'];
			$cobrar = isset($cobrarMap[$idCx]);
			$p['cobrar'] = $cobrar;
			if ($p['grupo'] === 'vencida') {
				$vencidas[] = $p;
				$totalVencFace += (float)$p['valor_face'];
				$totalVencEnc += (float)$p['total_com_encargos'];
			} else {
				$futuras[] = $p;
				$totalFutFace += (float)$p['valor_face'];
			}
			if ($cobrar) {
				$qtdCobrar++;
				$totalCobrarFace += (float)$p['valor_face'];
				$totalCobrarEnc += (float)$p['total_com_encargos'];
			} else {
				$qtdBaixar++;
				$totalBaixarFace += (float)$p['valor_face'];
				$totalMultaBase += (float)$p['valor_face'];
			}
		}

		$multaRescisoria = round(
			$totalMultaBase * ((float)$params['multa_cancelamento_pct'] / 100),
			2
		);
		$idsCobrar = array_keys($cobrarMap);

		return [
			'ok'                          => true,
			'params'                      => $params,
			'parcelas'                    => array_merge($vencidas, $futuras),
			'parcelas_cobrar'             => $idsCobrar,
			'vencidas'                    => $vencidas,
			'futuras'                     => $futuras,
			'total_cobrar_face'           => round($totalCobrarFace, 2),
			'total_cobrar_com_encargos'   => round($totalCobrarEnc, 2),
			'total_baixar_face'           => round($totalBaixarFace, 2),
			'total_vencidas_face'         => round($totalVencFace, 2),
			'total_vencidas_com_encargos' => round($totalVencEnc, 2),
			'total_futuras_face'          => round($totalFutFace, 2),
			'multa_rescisoria'            => $multaRescisoria,
			'total_geral_com_encargos'    => round($totalCobrarEnc + $multaRescisoria, 2),
			'qtd_vencidas'                => count($vencidas),
			'qtd_futuras'                 => count($futuras),
			'qtd_cobrar'                  => $qtdCobrar,
			'qtd_baixar'                  => $qtdBaixar,
			'max_parcelas_desistencia'    => self::DEFAULT_MAX_PARCELAS_DESISTENCIA,
		];
	}

	/** Simula encargos de um título do extrato (matrícula; acordos ficam sem encargos). */
	public static function encargosParaTitulo(
		int $idAdmin,
		int $idAluno,
		int $idTitulo,
		?string $dataReferencia = null
	): array {
		if ($idTitulo <= 0) {
			return ['ok' => false, 'message' => 'Título inválido.'];
		}
		$c = Caixa::getCaixaById($idTitulo);
		if (!$c instanceof Caixa || (int)$c->id_admin !== $idAdmin) {
			return ['ok' => false, 'message' => 'Título não encontrado.'];
		}
		if ((string)($c->tipo_transacao ?? '') !== 'Entrada') {
			return ['ok' => false, 'message' => 'Somente títulos de entrada.'];
		}
		if (!FinanceiroAlunoHelper::tituloAberto($c->status)) {
			return ['ok' => false, 'message' => 'Título já pago.'];
		}
		if ((int)($c->id_acordo ?? 0) > 0) {
			return [
				'ok' => true,
				'elegivel_encargos' => false,
				'valor_face' => round((float)($c->valor ?? 0), 2),
				'total_com_encargos' => round((float)($c->valor ?? 0), 2),
				'total_sem_encargos' => round((float)($c->valor ?? 0), 2),
			];
		}
		$idRef = (int)($c->id_ref ?? 0);
		if ($idRef <= 0) {
			return [
				'ok' => true,
				'elegivel_encargos' => false,
				'valor_face' => round((float)($c->valor ?? 0), 2),
				'total_com_encargos' => round((float)($c->valor ?? 0), 2),
				'total_sem_encargos' => round((float)($c->valor ?? 0), 2),
			];
		}
		$m = Matriculas::getMatriculaById($idRef);
		if (!$m || (int)$m->id_admin !== $idAdmin || (int)$m->id_aluno !== $idAluno) {
			return ['ok' => false, 'message' => 'Título não pertence a este aluno.'];
		}
		$params = self::parametrosPorMatricula($idRef);
		$dataReferencia = trim((string)($dataReferencia ?: date('Y-m-d')));
		$face = self::faceTituloMatricula($c);
		$calc = self::calcularAtraso(
			$face,
			(string)($c->vencimento ?? ''),
			$params,
			$dataReferencia
		);
		return array_merge(['ok' => true, 'valor_face' => $face], $calc);
	}

	/** Cria título avulso de multa rescisória (se valor > 0). */
	public static function lancarMultaRescisoria(
		int $idAdmin,
		int $idAluno,
		int $idMatricula,
		float $valor,
		string $descricaoExtra = ''
	): ?int {
		$valor = round($valor, 2);
		if ($valor <= 0) {
			return null;
		}
		$desc = 'Multa rescisória — matrícula #'.$idMatricula;
		if ($descricaoExtra !== '') {
			$desc .= ' — '.$descricaoExtra;
		}
		$ob = new Caixa();
		$ob->id_admin = $idAdmin;
		$ob->descricao = mb_substr($desc, 0, 250);
		$ob->valor = $valor;
		$ob->valor_pago = 0;
		$ob->vencimento = date('Y-m-d');
		$ob->data_pagamento = '';
		$ob->tipo_pagamento = '';
		$ob->tipo_transacao = 'Entrada';
		$ob->referencia = 'Multa rescisória';
		$ob->id_ref = $idMatricula;
		$ob->status = FinanceiroAlunoHelper::STATUS_ABERTO;
		$ob->lancarMovimentacao();
		return (int)($ob->id ?? 0) ?: null;
	}
}
