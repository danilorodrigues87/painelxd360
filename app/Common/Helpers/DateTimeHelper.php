<?php 

namespace App\Common\Helpers;

date_default_timezone_set('America/Sao_Paulo');

class DateTimeHelper {

    // Retorna a data de hoje no formato Y-m-d
    public static function hoje() {
        return date("Y-m-d");
    }

    // Retorna a data e hora atual no formato Y-m-d H:i:s
    public static function agora() {
        return date("Y-m-d H:i:s");
    }

    // Retorna a hora atual no formato H:i:s
    public static function horario() {
        return date("H:i:s");
    }

    // Extrai horario no formato H:i:s
    public static function extrairHorario($dataHora) {
        return $horario = date('H:i:s', strtotime($dataHora));
    }


    // Extrai o dia, mês e ano de uma data (em formato EN ou BR)
    public static function extrair_data($data, $opcao = 1) {
        if ($data instanceof \DateTime) {
            $data = $data->format($opcao === 1 ? 'Y-m-d' : 'd/m/Y');
        }
        
        // Opção 1-EN (Y-m-d), 2-BR (d/m/Y)
        if ($opcao == 1) {
            $dia = substr($data, 8, 2);
            $mes = substr($data, 5, 2);
            $ano = substr($data, 0, 4);
        } else {
            $dia = substr($data, 0, 2);
            $mes = substr($data, 3, 2);
            $ano = substr($data, 6, 4);
        }

        return [$dia, $mes, $ano];
    }

    // Transforma data do formato inglês (Y-m-d) para o brasileiro (d/m/Y)
    public static function databr($data) {

        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        return $data->format('d/m/Y');
    }

    // Transforma data do formato brasileiro (d/m/Y) para o inglês (Y-m-d)
    public static function dataEn($data) {
        if (is_string($data)) {
            $data = \DateTime::createFromFormat('d/m/Y', $data);
        }
        return $data->format('Y-m-d');
    }

    // Extrai o dia de uma data
    public static function extraiDia($data, $opcao = 1) {
        $data = self::extrair_data($data, $opcao);
        return $data[0];
    }

    // Extrai o mês de uma data
    public static function extraiMes($data, $opcao = 1) {
        $data = self::extrair_data($data, $opcao);
        return $data[1];
    }

    // Extrai o ano de uma data
    public static function extraiAno($data, $opcao = 1) {
        $data = self::extrair_data($data, $opcao);
        return $data[2];
    }

    // Subtrai duas datas e retorna a diferença
    public static function subtrairDatas($strData1, $strData2) {
        $datetime1 = $strData1 instanceof \DateTime ? $strData1 : new \DateTime($strData1);
        $datetime2 = $strData2 instanceof \DateTime ? $strData2 : new \DateTime($strData2);
        return $datetime1->diff($datetime2);
    }

    // Calcula a diferença entre duas datas em horas
    public static function diferencaEmHora($strData1, $strData2) {
        $datetime1 = $strData1 instanceof \DateTime ? $strData1 : new \DateTime($strData1);
        $datetime2 = $strData2 instanceof \DateTime ? $strData2 : new \DateTime($strData2);
        $diferenca = $datetime1->diff($datetime2, true);
        return 24 * $diferenca->d + $diferenca->h;
    }

    // Gera o timestamp de uma data no formato DD/MM/AAAA
    public static function geraTimestampDMA($data) {
        $partes = explode('/', $data);
        return mktime(0, 0, 0, $partes[1], $partes[0], $partes[2]);
    }

    // Gera o timestamp de uma data no formato Y-m-d
    public static function geraTimestampAMD($data) {
        $partes = explode('-', $data);
        return mktime(0, 0, 0, $partes[1], $partes[2], $partes[0]);
    }

    // Soma dias, meses e anos a uma data
    public static function somarData($data, $dias = 0, $meses = 0, $ano = 0, $opcao = 1) {
        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        $data->modify("+$dias days +$meses months +$ano years");
        return $data->format($opcao === 1 ? 'Y-m-d' : 'd/m/Y');
    }

    // Retorna o dia da semana por extenso
    public static function diasemanaExtenso($data, $opcao = 1) {
        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        $diasemana = $data->format('w');
        $dias = ["Domingo", "Segunda-Feira", "Terça-Feira", "Quarta-Feira", "Quinta-Feira", "Sexta-Feira", "Sábado"];
        return $dias[$diasemana];
    }

    // Retorna o dia da semana abreviado
    public static function diasemanaAbreviada($data, $opcao = 1) {
        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        $diasemana = $data->format('w');
        $dias = ["Dom", "Seg", "Ter", "Qua", "Qui", "Sex", "Sáb"];
        return $dias[$diasemana];
    }

    // Retorna o código do dia da semana (0 = Domingo, 6 = Sábado)
    public static function CodigodiaSemana($data, $opcao = 1) {
        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        return $data->format('w');
    }

    // Retorna o mês por extenso
    public static function imprimeMes($valor) {
        $meses = [
            1 => "Janeiro", 2 => "Fevereiro", 3 => "Março", 4 => "Abril", 5 => "Maio", 6 => "Junho",
            7 => "Julho", 8 => "Agosto", 9 => "Setembro", 10 => "Outubro", 11 => "Novembro", 12 => "Dezembro"
        ];
        return $meses[$valor] ?? '';
    }

    // Retorna o mês abreviado
    public static function imprimeMesAbreviado($valor) {
        $meses = [
            1 => "Jan", 2 => "Fev", 3 => "Mar", 4 => "Abr", 5 => "Mai", 6 => "Jun",
            7 => "Jul", 8 => "Ago", 9 => "Set", 10 => "Out", 11 => "Nov", 12 => "Dez"
        ];
        return $meses[$valor] ?? '';
    }

    // Retorna o último dia do mês
    public static function ultimoDiaMes($data) {
        $data = $data instanceof \DateTime ? $data : new \DateTime($data);
        return $data->format('t');
    }

    // Retorna a quantidade de dias no mês
    public static function qtdeDiasNoMes($data, $opcao = 1) {
        if (is_string($data)) {
            $data = new \DateTime($data);
        }
        return $data->format('t');
    }
}
