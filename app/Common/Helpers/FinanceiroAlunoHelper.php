<?php

namespace App\Common\Helpers;

use App\Model\Entity\Caixa;
use App\Model\Entity\FinanceiroAcordo;
use App\Model\Entity\Matriculas;
use App\Model\Entity\Trilhas;
use App\Model\Entity\User;
use App\Model\Db\Database;
use App\Session\User\Login as SessionUser;

/**
 * Extrato consolidado do aluno (todas as matrículas + acordos) e renegociação.
 */
class FinanceiroAlunoHelper {

	/** Status canônico ao gravar título em aberto. */
	public const STATUS_ABERTO = 'Em aberto';

	/** Status canônico ao gravar título pago. */
	public const STATUS_PAGO = 1;

	/** Tolerância em R$ para comparar valor face vs pago (PIX/float). */
	public const TOLERANCIA_VALOR = 0.05;

	public static function tituloAberto($status): bool {
		if ($status === 0 || $status === '0') {
			return true;
		}
		return (string)$status === 'Em aberto';
	}

	public static function tituloPago($status): bool {
		if ($status === 1 || $status === '1') {
			return true;
		}
		return (string)$status === 'Pago';
	}

	/**
	 * Compara valor pago com face do título (com tolerância de centavos).
	 */
	public static function valorCompativelComFace(float $valorPago, float $valorFace, float $tolerancia = self::TOLERANCIA_VALOR): bool {
		if ($valorPago <= 0 || $valorFace < 0) {
			return false;
		}
		return abs(round($valorPago, 2) - round($valorFace, 2)) <= $tolerancia;
	}

	/** Fragmento SQL: título em aberto (legado 0 / "0" / "Em aberto"). */
	public static function sqlTituloAberto(string $coluna = 'status'): string {
		$c = $coluna !== '' ? $coluna : 'status';
		return '('.$c.' = 0 OR '.$c.' = "0" OR '.$c.' = "Em aberto")';
	}

	/** Fragmento SQL: título pago (legado 1 / "1" / "Pago"). */
	public static function sqlTituloPago(string $coluna = 'status'): string {
		$c = $coluna !== '' ? $coluna : 'status';
		return '('.$c.' = 1 OR '.$c.' = "1" OR '.$c.' = "Pago")';
	}

	/**
	 * Desconto de pontualidade (10%) se elegível: flag na matrícula e vencimento > hoje.
	 * @return array{elegivel:bool,valor:float,desconto:float,valor_com_desconto:float,valor_pagar:float}
	 */
	public static function calcularPontualidade(float $valorFace, $vencimento, $descontoPontualidadeAtivo): array {
		$valor = round((float)$valorFace, 2);
		$out = [
			'elegivel' => false,
			'valor' => $valor,
			'desconto' => 0.0,
			'valor_com_desconto' => $valor,
			'valor_pagar' => $valor,
		];
		$temFlag = !empty($descontoPontualidadeAtivo) && (int)$descontoPontualidadeAtivo !== 0;
		$venc = is_string($vencimento) ? $vencimento : (string)$vencimento;
		$hoje = date('Y-m-d');
		if (!$temFlag || $venc === '' || $venc <= $hoje) {
			return $out;
		}
		$comDesc = round($valor * 0.90, 2);
		$desc = round($valor - $comDesc, 2);
		$out['elegivel'] = true;
		$out['desconto'] = $desc;
		$out['valor_com_desconto'] = $comDesc;
		$out['valor_pagar'] = $comDesc;
		return $out;
	}

	/**
	 * @return array{ok:bool,message?:string,aluno?:array,matriculas?:array,acordos?:array,titulos?:array,totais?:array}
	 */
	public static function extrato(int $idAdmin, int $idAluno): array {
		$aluno = User::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno não encontrado.'];
		}

		$matriculasResumo = [];
		$titulos = [];
		$hoje = date('Y-m-d');

		MatriculaStatusHelper::encerrarVencidasTenant($idAdmin);

		$mats = Matriculas::getMatriculas(
			'id_admin = '.(int)$idAdmin.' AND id_aluno = '.(int)$idAluno,
			'id DESC'
		);
		while ($m = $mats->fetchObject(Matriculas::class)) {
			$trilha = Trilhas::getTrilhaById((int)$m->id_trilha);
			$nomeCurso = $trilha ? (string)$trilha->nome : ('Trilha #'.(int)$m->id_trilha);
			$statusMat = (int)($m->status ?? 0);
			$statusLabel = MatriculaStatusHelper::labelStatus($statusMat);

			$pago = 0.0;
			$aberto = 0.0;
			$vencido = 0.0;
			$qtd = 0;

			$cx = Caixa::getCaixa(
				'id_admin = '.(int)$idAdmin
				.' AND id_ref = '.(int)$m->id
				.' AND tipo_transacao = "Entrada"',
				'vencimento ASC, id ASC'
			);
			while ($c = $cx->fetchObject(Caixa::class)) {
				$qtd++;
				$row = self::mapTitulo($c, 'matricula', (int)$m->id, $nomeCurso);
				$titulos[] = $row;
				if ($row['status'] === 'pago') {
					$pago += (float)$row['valor_pago'] > 0 ? (float)$row['valor_pago'] : (float)$row['valor'];
				} elseif ($row['status'] === 'vencido') {
					$vencido += (float)($row['total_com_encargos'] ?? $row['valor']);
					$aberto += (float)($row['total_com_encargos'] ?? $row['valor']);
				} elseif ($row['status'] === 'aberto') {
					$aberto += (float)$row['valor'];
				}
			}

			$matriculasResumo[] = [
				'id' => (int)$m->id,
				'curso' => $nomeCurso,
				'status' => $statusLabel,
				'status_code' => $statusMat,
				'tipo_parcelamento' => (string)($m->tipo_parcelamento ?? ''),
				'qtd_titulos' => $qtd,
				'total_pago' => round($pago, 2),
				'total_aberto' => round($aberto, 2),
				'total_vencido' => round($vencido, 2),
			];
		}

		$acordosResumo = [];
		if (FinanceiroAcordo::tabelasExistem() && FinanceiroAcordo::caixaTemIdAcordo()) {
			foreach (FinanceiroAcordo::listByAluno($idAluno, $idAdmin) as $ac) {
				$pago = 0.0;
				$aberto = 0.0;
				$vencido = 0.0;
				$qtd = 0;
				$parcelasAcordo = [];
				$label = 'Acordo #'.(int)$ac->id;
				$cx = Caixa::getCaixa(
					'id_admin = '.(int)$idAdmin
					.' AND id_acordo = '.(int)$ac->id
					.' AND tipo_transacao = "Entrada"',
					'vencimento ASC, id ASC'
				);
				while ($c = $cx->fetchObject(Caixa::class)) {
					$qtd++;
					$row = self::mapTitulo($c, 'acordo', (int)$ac->id, $label);
					$parcelasAcordo[] = $row;
					$titulos[] = $row;
					if ($row['status'] === 'pago') {
						$pago += (float)$row['valor_pago'] > 0 ? (float)$row['valor_pago'] : (float)$row['valor'];
					} elseif ($row['status'] === 'vencido') {
						$vencido += (float)$row['valor'];
						$aberto += (float)$row['valor'];
					} elseif ($row['status'] === 'aberto') {
						$aberto += (float)$row['valor'];
					}
				}
				$acordosResumo[] = [
					'id' => (int)$ac->id,
					'label' => $label,
					'valor_total' => (float)$ac->valor_total,
					'valor_entrada' => round(max(0, (float)($ac->valor_entrada ?? 0)), 2),
					'qtd_parcelas' => (int)$ac->qtd_parcelas,
					'status' => (string)$ac->status,
					'observacao' => (string)($ac->observacao ?? ''),
					'created_at' => (string)($ac->created_at ?? ''),
					'qtd_titulos' => $qtd,
					'total_pago' => round($pago, 2),
					'total_aberto' => round($aberto, 2),
					'total_vencido' => round($vencido, 2),
					'parcelas' => $parcelasAcordo,
				];
			}
		}

		usort($titulos, static function ($a, $b) {
			return strcmp((string)$a['vencimento'], (string)$b['vencimento']);
		});

		$totais = [
			'pago' => 0.0,
			'aberto' => 0.0,
			'vencido' => 0.0,
			'titulos' => count($titulos),
		];
		foreach ($titulos as $t) {
			if ($t['status'] === 'pago') {
				$totais['pago'] += (float)$t['valor_pago'] > 0 ? (float)$t['valor_pago'] : (float)$t['valor'];
			} elseif ($t['status'] === 'vencido') {
				$tot = (float)($t['total_com_encargos'] ?? $t['valor']);
				$totais['vencido'] += $tot;
				$totais['aberto'] += $tot;
			} elseif ($t['status'] === 'aberto') {
				$totais['aberto'] += (float)$t['valor'];
			}
		}
		$totais['pago'] = round($totais['pago'], 2);
		$totais['aberto'] = round($totais['aberto'], 2);
		$totais['vencido'] = round($totais['vencido'], 2);

		$divida = EncargosContratoHelper::calcularDividaAluno($idAdmin, $idAluno, $hoje);
		if (!empty($divida['ok'])) {
			$totais['divida_atualizada'] = (float)($divida['total_com_encargos'] ?? 0);
			$totais['divida_face'] = (float)($divida['total_face'] ?? 0);
			$totais['divida_multa'] = (float)($divida['total_multa'] ?? 0);
			$totais['divida_juros'] = (float)($divida['total_juros'] ?? 0);
			$totais['qtd_titulos_abertos'] = (int)($divida['qtd_abertos'] ?? 0);
		}

		return [
			'ok' => true,
			'aluno' => [
				'id' => (int)$aluno->id,
				'nome' => (string)$aluno->nome,
				'email' => (string)$aluno->email,
				'cpf' => (string)($aluno->cpf ?? ''),
				'whatsapp' => (string)($aluno->whatsapp ?? ''),
			],
			'matriculas' => $matriculasResumo,
			'acordos' => $acordosResumo,
			'titulos' => $titulos,
			'totais' => $totais,
			'pode_renegociar' => FinanceiroAcordo::tabelasExistem() && FinanceiroAcordo::caixaTemIdAcordo(),
			'hoje' => $hoje,
		];
	}

	/** @return array<string,mixed> */
	private static function mapTitulo(Caixa $c, string $origem, int $origemId, string $origemLabel): array {
		$hoje = date('Y-m-d');
		$venc = (string)($c->vencimento ?? '');
		$tipoPag = (string)($c->tipo_pagamento ?? '');
		$status = 'pago';
		if (self::tituloAberto($c->status)) {
			$status = ($venc !== '' && $venc < $hoje) ? 'vencido' : 'aberto';
		} elseif (MatriculaStatusHelper::ehBaixaAdministrativa($tipoPag)) {
			$status = $tipoPag === MatriculaStatusHelper::TIPO_CANCELAMENTO ? 'cancelada' : 'renegociada';
		} elseif (!self::tituloPago($c->status)) {
			$status = self::tituloAberto($c->status) ? 'aberto' : 'pago';
		}

		$valorFace = round((float)($c->valor ?? 0), 2);
		$multa = 0.0;
		$juros = 0.0;
		$totalComEnc = $valorFace;
		$elegivelEnc = false;
		$refTitulo = trim((string)($c->referencia ?? ''));
		$ehMultaRescisoria = ($refTitulo === 'Multa rescisória');
		if (($status === 'vencido' || $status === 'aberto') && ($origem === 'matricula' || $ehMultaRescisoria)) {
			$vals = EncargosContratoHelper::resolverValoresTituloCaixa($c, true, $hoje);
			$valorFace = $vals['face'];
			$totalComEnc = $vals['valor'];
			$multa = (float)($vals['enc']['multa'] ?? 0);
			$juros = (float)($vals['enc']['juros'] ?? 0);
			$elegivelEnc = !empty($vals['elegivel_encargos']);
		}
		if ($ehMultaRescisoria) {
			$origemLabel = 'Multa rescisória';
		}

		return [
			'id' => (int)$c->id,
			'origem' => $origem,
			'origem_id' => $origemId,
			'origem_label' => $origemLabel,
			'descricao' => (string)($c->descricao ?? ''),
			'referencia' => (string)($c->referencia ?? ''),
			'valor' => $valorFace,
			'valor_face' => $valorFace,
			'multa' => round($multa, 2),
			'juros' => round($juros, 2),
			'total_com_encargos' => round($totalComEnc, 2),
			'elegivel_encargos' => $elegivelEnc,
			'valor_pago' => (float)($c->valor_pago ?? 0),
			'vencimento' => $venc,
			'data_pagamento' => (string)($c->data_pagamento ?? ''),
			'tipo_pagamento' => $tipoPag,
			'status' => $status,
			'status_raw' => (string)($c->status ?? ''),
			'selecionavel' => $status === 'aberto' || $status === 'vencido',
		];
	}

	/**
	 * Renegocia títulos em aberto: marca como Renegociação e cria novo acordo + parcelas.
	 * Entrada (opcional) no primeiro vencimento; saldo restante dividido em parcelas mensais.
	 *
	 * @param int[] $idsTitulos
	 * @return array{ok:bool,message:string,id_acordo?:int}
	 */
	public static function renegociar(
		int $idAdmin,
		int $idAluno,
		array $idsTitulos,
		float $valorTotal,
		float $valorEntrada,
		int $qtdParcelasRestante,
		string $primeiroVencimento,
		string $observacao = ''
	): array {
		if (!FinanceiroAcordo::tabelasExistem() || !FinanceiroAcordo::caixaTemIdAcordo()) {
			return ['ok' => false, 'message' => 'Execute database/financeiro_acordos.sql no phpMyAdmin.'];
		}
		$aluno = User::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno inválido.'];
		}
		$idsTitulos = array_values(array_unique(array_filter(array_map('intval', $idsTitulos))));
		if (empty($idsTitulos)) {
			return ['ok' => false, 'message' => 'Selecione ao menos um título em aberto.'];
		}
		$valorTotal = round(max(0, $valorTotal), 2);
		if ($valorTotal <= 0) {
			return ['ok' => false, 'message' => 'Informe o valor total do acordo.'];
		}
		$valorEntrada = round(max(0, $valorEntrada), 2);
		if ($valorEntrada > $valorTotal) {
			return ['ok' => false, 'message' => 'A entrada não pode ser maior que o valor total.'];
		}
		$qtdParcelasRestante = max(0, min(120, $qtdParcelasRestante));
		$valorRestante = round($valorTotal - $valorEntrada, 2);
		if ($valorEntrada <= 0 && $qtdParcelasRestante < 1) {
			return ['ok' => false, 'message' => 'Informe a quantidade de parcelas do restante.'];
		}
		if ($valorEntrada > 0 && $valorRestante > 0 && $qtdParcelasRestante < 1) {
			return ['ok' => false, 'message' => 'Informe a quantidade de parcelas do restante.'];
		}
		if ($valorRestante <= 0 && $qtdParcelasRestante > 0) {
			return ['ok' => false, 'message' => 'Não há saldo restante para parcelar.'];
		}
		if ($valorEntrada <= 0 && $valorRestante <= 0) {
			return ['ok' => false, 'message' => 'Informe entrada ou parcelas do restante.'];
		}
		$ts = strtotime($primeiroVencimento);
		if ($ts === false) {
			return ['ok' => false, 'message' => 'Data do primeiro vencimento inválida.'];
		}
		$primeiroVencimento = date('Y-m-d', $ts);
		$diaVenc = (int)date('d', $ts);

		$titulosOk = [];
		foreach ($idsTitulos as $idCx) {
			$c = Caixa::getCaixaById($idCx);
			if (!$c instanceof Caixa || (int)$c->id_admin !== $idAdmin) {
				return ['ok' => false, 'message' => 'Título #'.$idCx.' inválido.'];
			}
			if (!self::tituloAberto($c->status)) {
				return ['ok' => false, 'message' => 'Título #'.$idCx.' não está em aberto.'];
			}
			// Pertence ao aluno?
			if ((int)($c->id_acordo ?? 0) > 0) {
				$ac = FinanceiroAcordo::getById((int)$c->id_acordo);
				if (!$ac || (int)$ac->id_aluno !== $idAluno || (int)$ac->id_admin !== $idAdmin) {
					return ['ok' => false, 'message' => 'Título #'.$idCx.' não pertence a este aluno.'];
				}
			} else {
				$idRef = (int)($c->id_ref ?? 0);
				if ($idRef <= 0) {
					return ['ok' => false, 'message' => 'Título #'.$idCx.' sem vínculo.'];
				}
				$m = Matriculas::getMatriculaById($idRef);
				if (!$m || (int)$m->id_admin !== $idAdmin || (int)$m->id_aluno !== $idAluno) {
					return ['ok' => false, 'message' => 'Título #'.$idCx.' não pertence a este aluno.'];
				}
			}
			$titulosOk[] = $c;
		}

		$valorParcRest = 0.0;
		$ultimaParcRest = 0.0;
		if ($valorRestante > 0 && $qtdParcelasRestante > 0) {
			$valorParcRest = round($valorRestante / $qtdParcelasRestante, 2);
			$somaParc = round($valorParcRest * ($qtdParcelasRestante - 1), 2);
			$ultimaParcRest = round($valorRestante - $somaParc, 2);
		}

		$userLoged = SessionUser::getUserLogedData();
		$idUser = (int)($userLoged['usuario']['id'] ?? 0);

		$acordo = new FinanceiroAcordo();
		$acordo->id_admin = $idAdmin;
		$acordo->id_aluno = $idAluno;
		$acordo->valor_total = $valorTotal;
		$acordo->valor_entrada = $valorEntrada;
		$acordo->valor_parcela = $qtdParcelasRestante > 0 ? $valorParcRest : $valorEntrada;
		$acordo->qtd_parcelas = $valorEntrada > 0 ? $qtdParcelasRestante : max(1, $qtdParcelasRestante);
		$acordo->dia_vencimento = $diaVenc;
		$acordo->primeiro_vencimento = $primeiroVencimento;
		$acordo->observacao = trim($observacao) !== '' ? trim($observacao) : null;
		$acordo->ids_titulos_origem = json_encode($idsTitulos, JSON_UNESCAPED_UNICODE);
		$acordo->status = 'ativo';
		$acordo->id_usuario = $idUser > 0 ? $idUser : null;
		$idAcordo = $acordo->cadastrar();
		if ($idAcordo <= 0) {
			return ['ok' => false, 'message' => 'Falha ao criar o acordo.'];
		}

		$obsBaixa = 'Renegociação → Acordo #'.$idAcordo;
		foreach ($titulosOk as $c) {
			$c->status = self::STATUS_PAGO;
			$c->tipo_pagamento = 'Renegociação';
			$c->data_pagamento = date('Y-m-d');
			$c->valor_pago = 0;
			$c->atualizar();
			// Anexa nota na descrição se ainda não tiver
			$desc = (string)($c->descricao ?? '');
			if (strpos($desc, 'Acordo #'.$idAcordo) === false) {
				(new Database('caixa'))->update('id = '.(int)$c->id, [
					'descricao' => mb_substr(trim($desc.' | '.$obsBaixa), 0, 250),
					'ultima_alteracao' => date('Y-m-d H:i:s'),
				]);
			}
		}

		$nomeAluno = (string)$aluno->nome;
		$venc = $primeiroVencimento;
		$qtdTitulos = 0;

		if ($valorEntrada > 0) {
			$ob = new Caixa();
			$ob->id_admin = $idAdmin;
			$ob->descricao = 'Acordo #'.$idAcordo.' '.$nomeAluno.' entrada';
			$ob->tipo_transacao = 'Entrada';
			$ob->valor = $valorEntrada;
			$ob->vencimento = $venc;
			$ob->referencia = 'Acordo financeiro';
			$ob->id_ref = 0;
			$ob->id_acordo = $idAcordo;
			$ob->status = self::STATUS_ABERTO;
			$ob->tipo_pagamento = '';
			$ob->valor_pago = 0;
			$ob->data_pagamento = null;
			$ob->txt_id = '';
			$ob->pix_copia_cola = '';
			$ob->nosso_numero = '';
			$ob->lancarMovimentacao();
			$qtdTitulos++;

			$tsNext = strtotime($venc.' +1 month');
			if ($tsNext !== false) {
				$y = (int)date('Y', $tsNext);
				$m = (int)date('m', $tsNext);
				$d = min($diaVenc, (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m))));
				$venc = sprintf('%04d-%02d-%02d', $y, $m, $d);
			}
		}

		for ($n = 1; $n <= $qtdParcelasRestante; $n++) {
			$valorN = ($n === $qtdParcelasRestante) ? $ultimaParcRest : $valorParcRest;
			$ob = new Caixa();
			$ob->id_admin = $idAdmin;
			$ob->descricao = 'Acordo #'.$idAcordo.' '.$nomeAluno.' parc '.$n.'/'.$qtdParcelasRestante;
			$ob->tipo_transacao = 'Entrada';
			$ob->valor = $valorN;
			$ob->vencimento = $venc;
			$ob->referencia = 'Acordo financeiro';
			$ob->id_ref = 0;
			$ob->id_acordo = $idAcordo;
			$ob->status = self::STATUS_ABERTO;
			$ob->tipo_pagamento = '';
			$ob->valor_pago = 0;
			$ob->data_pagamento = null;
			$ob->txt_id = '';
			$ob->pix_copia_cola = '';
			$ob->nosso_numero = '';
			$ob->lancarMovimentacao();
			$qtdTitulos++;

			$tsNext = strtotime($venc.' +1 month');
			if ($tsNext === false) {
				break;
			}
			$y = (int)date('Y', $tsNext);
			$m = (int)date('m', $tsNext);
			$d = min($diaVenc, (int)date('t', strtotime(sprintf('%04d-%02d-01', $y, $m))));
			$venc = sprintf('%04d-%02d-%02d', $y, $m, $d);
		}

		$msgPartes = ['Acordo #'.$idAcordo.' criado'];
		if ($valorEntrada > 0) {
			$msgPartes[] = 'entrada R$ '.number_format($valorEntrada, 2, ',', '.');
		}
		if ($qtdParcelasRestante > 0) {
			$msgPartes[] = $qtdParcelasRestante.' parcela(s) do restante';
		}
		$msgPartes[] = $qtdTitulos.' título(s) no carnê';

		return [
			'ok' => true,
			'message' => implode(' · ', $msgPartes).'.',
			'id_acordo' => $idAcordo,
		];
	}

	/**
	 * Payload para API aluno (só leitura).
	 * @return array{hasFinance:bool,totals:array,items:array}
	 */
	public static function forStudentApi(int $idAdmin, int $idAluno): array {
		$res = self::extrato($idAdmin, $idAluno);
		if (empty($res['ok'])) {
			return ['hasFinance' => false, 'totals' => ['paid' => 0, 'open' => 0, 'overdue' => 0], 'items' => []];
		}
		$items = [];
		foreach ($res['titulos'] as $t) {
			$items[] = [
				'id' => (string)$t['id'],
				'origin' => $t['origem_label'],
				'description' => $t['descricao'],
				'amount' => (float)$t['valor'],
				'paidAmount' => (float)$t['valor_pago'],
				'dueDate' => $t['vencimento'] ? date('c', strtotime($t['vencimento'])) : null,
				'paidAt' => !empty($t['data_pagamento']) && $t['data_pagamento'] !== '0000-00-00'
					? date('c', strtotime($t['data_pagamento']))
					: null,
				'status' => $t['status'] === 'vencido' ? 'overdue' : ($t['status'] === 'pago' ? 'paid' : 'open'),
				'paymentType' => $t['tipo_pagamento'] ?: null,
			];
		}
		$tot = $res['totais'];
		return [
			'hasFinance' => count($items) > 0,
			'totals' => [
				'paid' => (float)$tot['pago'],
				'open' => (float)$tot['aberto'],
				'overdue' => (float)$tot['vencido'],
			],
			'items' => $items,
		];
	}

	/**
	 * Baixa manual de um título do aluno (matrícula ou acordo).
	 * @return array{ok:bool,message:string}
	 */
	public static function darBaixa(
		int $idAdmin,
		int $idAluno,
		int $idTitulo,
		float $valorPago,
		string $tipoPagamento,
		string $dataPagamento = ''
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
		if (!self::tituloAberto($c->status)) {
			return ['ok' => false, 'message' => 'Este título já está pago.'];
		}

		// Pertence ao aluno?
		if ((int)($c->id_acordo ?? 0) > 0) {
			if (!FinanceiroAcordo::tabelasExistem()) {
				return ['ok' => false, 'message' => 'Tabelas de acordo ausentes.'];
			}
			$ac = FinanceiroAcordo::getById((int)$c->id_acordo);
			if (!$ac || (int)$ac->id_aluno !== $idAluno || (int)$ac->id_admin !== $idAdmin) {
				return ['ok' => false, 'message' => 'Título não pertence a este aluno.'];
			}
		} else {
			$idRef = (int)($c->id_ref ?? 0);
			if ($idRef <= 0) {
				return ['ok' => false, 'message' => 'Título sem vínculo com aluno.'];
			}
			$m = Matriculas::getMatriculaById($idRef);
			if (!$m || (int)$m->id_admin !== $idAdmin || (int)$m->id_aluno !== $idAluno) {
				return ['ok' => false, 'message' => 'Título não pertence a este aluno.'];
			}
		}

		$tipoPagamento = trim($tipoPagamento);
		if ($tipoPagamento === '') {
			return ['ok' => false, 'message' => 'Selecione a forma de pagamento.'];
		}
		if ($valorPago <= 0) {
			$valorPago = (float)$c->valor;
		}
		if ($dataPagamento === '') {
			$dataPagamento = date('Y-m-d');
		}
		$ts = strtotime($dataPagamento);
		if ($ts === false) {
			return ['ok' => false, 'message' => 'Data de pagamento inválida.'];
		}
		$dataPagamento = date('Y-m-d', $ts);

		$valsComEnc = EncargosContratoHelper::resolverValoresTituloCaixa($c, true, $dataPagamento);
		$valsSemEnc = EncargosContratoHelper::resolverValoresTituloCaixa($c, false, $dataPagamento);
		$okValor = self::valorCompativelComFace($valorPago, $valsComEnc['valor'])
			|| self::valorCompativelComFace($valorPago, $valsSemEnc['valor']);
		if (!$okValor) {
			return [
				'ok' => false,
				'message' => 'Valor pago incompatível. Esperado face R$ '
					.number_format($valsSemEnc['valor'], 2, ',', '.')
					.($valsComEnc['elegivel_encargos']
						? ' ou com encargos R$ '.number_format($valsComEnc['valor'], 2, ',', '.')
						: ''),
			];
		}

		$c->valor_pago = round($valorPago, 2);
		$c->data_pagamento = date('Y-m-d', $ts);
		$c->tipo_pagamento = $tipoPagamento;
		$c->status = self::STATUS_PAGO;
		$c->atualizar();

		return ['ok' => true, 'message' => 'Baixa registrada.'];
	}

	/**
	 * Baixa em lote: cada título pelo valor integral.
	 * @param int[] $idsTitulos
	 * @return array{ok:bool,message:string,baixados?:int}
	 */
	public static function darBaixaLote(
		int $idAdmin,
		int $idAluno,
		array $idsTitulos,
		string $tipoPagamento,
		string $dataPagamento = ''
	): array {
		$idsTitulos = array_values(array_unique(array_filter(array_map('intval', $idsTitulos))));
		if (empty($idsTitulos)) {
			return ['ok' => false, 'message' => 'Selecione ao menos uma parcela.'];
		}
		$tipoPagamento = trim($tipoPagamento);
		if ($tipoPagamento === '') {
			return ['ok' => false, 'message' => 'Selecione a forma de pagamento.'];
		}
		$ok = 0;
		$erros = [];
		foreach ($idsTitulos as $idTitulo) {
			$c = Caixa::getCaixaById($idTitulo);
			$valor = 0;
			if ($c instanceof Caixa) {
				$vals = EncargosContratoHelper::resolverValoresTituloCaixa($c, true, $dataPagamento ?: date('Y-m-d'));
				$valor = $vals['valor'];
			}
			$res = self::darBaixa($idAdmin, $idAluno, $idTitulo, $valor, $tipoPagamento, $dataPagamento);
			if (!empty($res['ok'])) {
				$ok++;
			} else {
				$erros[] = '#'.$idTitulo.': '.($res['message'] ?? 'erro');
			}
		}
		if ($ok === 0) {
			return ['ok' => false, 'message' => $erros ? implode(' ', $erros) : 'Nenhuma parcela baixada.'];
		}
		$msg = $ok.' parcela(s) baixada(s).';
		if ($erros) {
			$msg .= ' Alguns falharam: '.implode(' ', $erros);
		}
		return ['ok' => true, 'message' => $msg, 'baixados' => $ok];
	}
}
