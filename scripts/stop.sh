#!/bin/bash

#
# Script de parada do Chat4All
# Para todos os serviços
#

set -e

echo "================================================"
echo "  Parando Chat4All..."
echo "================================================"
echo ""

docker-compose down

echo ""
echo "✅ Serviços parados com sucesso!"
echo ""
echo "Para remover volumes (dados persistentes):"
echo "  docker-compose down -v"
echo ""
