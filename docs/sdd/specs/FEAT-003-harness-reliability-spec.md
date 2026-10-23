---
data: 2026-10-23
tipo: especificacao
estado: Sucesso
---

# FEAT-003: Avaliação e robustez do harness

Data: 2026-10-23. Estado: Concluído e aplicado localmente.

## Pedido
Ampliar os testes do plugin como harness, identificar falhas de orquestração e corrigir as que forem reproduzidas, mantendo as confirmações e as perguntas por passos.

## Contrato
- Recuperar de argumentos inválidos, ferramenta inexistente, falha transitória e UI mal formada, devolvendo feedback ao modelo sem inventar resultados.
- Parar entre chamadas da mesma ronda, sem executar as seguintes nem propor novas escritas. Guardar uma resposta por tool_call_id para o histórico continuar válido. Não interromper à força um handler já iniciado.
- Cumprir o limite de rondas mesmo se o modelo insistir em chamar ferramentas na resposta final; apresentar explicação se não houver texto nem UI útil. Respostas só visuais continuam válidas.
- Excepções inesperadas ficam nos logs do servidor. O modelo e a conversa recebem erro genérico; erros de validação e de autorização continuam distintos.
- Revalidar disponibilidade e contrato ao confirmar uma proposta antiga. Nunca executar uma escrita antes da confirmação.

## Validação
12 cenários novos em Pest, suite completa, testes JS e fluxos no browser com o modelo real. Registar resultados e limites sem apresentar testes determinísticos como medição da inteligência do modelo. Sem publicação, commits ou produção.

## Evidência
266 testes PHP (1028 assertions) e 9 testes JS aprovados. Quatro fluxos reais no browser, sem erros de consola; fixtures removidas. Detalhe: [avaliação do harness](../../investigations/2026-10-23-harness.md).
