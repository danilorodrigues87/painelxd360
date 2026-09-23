<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_Movimentacoes
{
    public $id;
    public $id_produto;
    public $tipo; // entrada | saida | ajuste
    public $quantidade;
    public $saldo_anterior;
    public $saldo_atual;
    public $observacao;
    public $id_admin;
    public $created_at;

    /**
     * Registrar movimentação de estoque
     */
    public function registrar()
    {
        $obDatabase = new Database('stq_movimentacoes');

        $this->id = $obDatabase->insert([
            'id_produto'      => $this->id_produto,
            'tipo'            => $this->tipo,
            'quantidade'      => $this->quantidade,
            'saldo_anterior'  => $this->saldo_anterior,
            'saldo_atual'     => $this->saldo_atual,
            'observacao'      => $this->observacao,
            'id_admin'        => $this->id_admin,
            'created_at'      => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /**
     * Atalho estático para facilitar uso nas outras classes
     */
    public static function registrarMovimentacao(
        int $idProduto,
        string $tipo,
        int $quantidade,
        int $saldoAnterior,
        int $saldoAtual,
        ?string $observacao,
        int $idAdmin
    ): bool {
        $mov = new self();
        $mov->id_produto     = $idProduto;
        $mov->tipo           = $tipo;
        $mov->quantidade     = $quantidade;
        $mov->saldo_anterior = $saldoAnterior;
        $mov->saldo_atual    = $saldoAtual;
        $mov->observacao     = $observacao;
        $mov->id_admin       = $idAdmin;

        return $mov->registrar();
    }

    /**
     * Buscar movimentações de um produto
     */
    public static function getByProduto(int $idProduto, $limit = null)
    {
        return (new Database('stq_movimentacoes'))->select(
            'id_produto = ' . $idProduto,
            'created_at DESC',
            $limit
        );
    }

    /**
     * Buscar movimentação pelo ID
     */
    public static function getById(int $id)
    {
        return (new Database('stq_movimentacoes'))
            ->select('id = ' . $id)
            ->fetchObject(self::class);
    }

    /**
     * Listar todas as movimentações (com filtros opcionais)
     */
    public static function getAll($where = null, $order = 'created_at DESC', $limit = null)
    {
        return (new Database('stq_movimentacoes'))->select(
            $where,
            $order,
            $limit
        );
    }
}
