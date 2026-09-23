<?php

namespace App\Model\Entity\Estoque;

use App\Model\Db\Database;

class Stq_Produtos
{
    public $id;
    public $nome;
    public $id_categoria;
    public $quantidade;
    public $descricao;
    public $valor_custo;
    public $valor_venda;
    public $sku;
    public $status;
    public $id_admin;
    public $created_at;
    public $updated_at;

    /* =====================================
     * BUSCAS
     * ===================================== */

    public static function getById(int $id)
    {
        return (new Database('stq_produtos'))
            ->select('id = ' . (int)$id)
            ->fetchObject(self::class);
    }

    public static function getByIdAdmin(int $id, int $idAdmin)
    {
        return (new Database('stq_produtos'))
            ->select('id = ' . (int)$id . ' AND id_admin = ' . (int)$idAdmin)
            ->fetchObject(self::class);
    }

    public static function getAll(
        $where = null,
        $order = null,
        $limit = null,
        $fields = '*',
        $innerJoin = null
    ) {
        return (new Database('stq_produtos'))
            ->select($where, $order, $limit, $fields, $innerJoin);
    }

    public static function getBySku(string $sku, ?int $idAdmin = null)
    {
        $skuEsc = addslashes($sku);
        $where = "sku = '{$skuEsc}'";
        if ($idAdmin !== null) {
            $where .= ' AND id_admin = ' . (int)$idAdmin;
        }
        return (new Database('stq_produtos'))
            ->select($where)
            ->fetchObject(self::class);
    }

    /* =====================================
     * CADASTRO
     * ===================================== */

    public function cadastrar(): bool
    {
        $this->id = (new Database('stq_produtos'))->insert([
            'nome'         => $this->nome,
            'id_categoria' => $this->id_categoria,
            'quantidade'   => $this->quantidade ?? 0,
            'descricao'    => $this->descricao,
            'valor_custo'  => $this->valor_custo,
            'valor_venda'  => $this->valor_venda,
            'sku'          => $this->sku,
            'status'       => $this->status ?? 1,
            'id_admin'     => $this->id_admin,
            'created_at'   => date('Y-m-d H:i:s')
        ]);

        return true;
    }

    /* =====================================
     * ATUALIZA DADOS (SEM ESTOQUE)
     * ===================================== */

    public function atualizar(): bool
    {
        return (new Database('stq_produtos'))->update(
            'id = ' . (int)$this->id,
            [
                'nome'         => $this->nome,
                'id_categoria' => $this->id_categoria,
                'descricao'    => $this->descricao,
                'valor_custo'  => $this->valor_custo,
                'valor_venda'  => $this->valor_venda,
                'sku'          => $this->sku,
                'status'       => $this->status ?? 1,
                'updated_at'   => date('Y-m-d H:i:s')
            ]
        );
    }

    public function inativar(): bool
    {
        return (new Database('stq_produtos'))->update(
            'id = ' . (int)$this->id,
            [
                'status' => 0,
                'updated_at' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /* =====================================
     * ESTOQUE
     * ===================================== */

    public function movimentarEstoque(
        string $tipo,
        int $quantidade,
        int $idAdmin,
        ?string $observacao = null
    ): bool {

        if ($quantidade <= 0) {
            throw new \Exception('Quantidade inválida');
        }

        $saldoAnterior = (int) $this->quantidade;

        switch ($tipo) {
            case 'entrada':
                $saldoAtual = $saldoAnterior + $quantidade;
                break;

            case 'saida':
                if ($quantidade > $saldoAnterior) {
                    throw new \Exception('Estoque insuficiente');
                }
                $saldoAtual = $saldoAnterior - $quantidade;
                break;

            case 'ajuste':
                $saldoAtual = $quantidade;
                break;

            default:
                throw new \Exception('Tipo inválido');
        }

        (new Database('stq_produtos'))->update(
            'id = ' . $this->id,
            [
                'quantidade' => $saldoAtual,
                'updated_at' => date('Y-m-d H:i:s')
            ]
        );

        $this->quantidade = $saldoAtual;

        Stq_Movimentacoes::registrarMovimentacao(
            $this->id,
            $tipo,
            $quantidade,
            $saldoAnterior,
            $saldoAtual,
            $observacao,
            $idAdmin
        );

        return true;
    }

    public function entradaEstoque(int $qtd, int $idAdmin, ?string $obs = null): bool
    {
        return $this->movimentarEstoque('entrada', $qtd, $idAdmin, $obs);
    }

    public function saidaEstoque(int $qtd, int $idAdmin, ?string $obs = null): bool
    {
        return $this->movimentarEstoque('saida', $qtd, $idAdmin, $obs);
    }

    public function ajustarEstoque(int $novoSaldo, int $idAdmin, ?string $obs = null): bool
    {
        return $this->movimentarEstoque('ajuste', $novoSaldo, $idAdmin, $obs);
    }
}
