# FEAT-002: Criação fiável de capacidades reutilizáveis

Data: 2026-10-23. Estado: Concluído e aplicado localmente, sem publicação.

## Problema
O assistente interpretou criar uma capacidade de comparação como executar uma pesquisa. Pediu repetidamente uma referência de execução e recusou a criação porque create_tool só cria resource/metrics, embora create_skill permita compor ferramentas. A skill criada mais tarde descreveu um valor de enum inexistente; a chamada actual não valida o contrato e pode cair silenciosamente num comportamento por omissão.

## Requisitos
- RF-01: instruções distinguem criação reutilizável de execução imediata. Dados variáveis (pessoa, período, referência) ficam para o momento de usar a skill. Escolher create_tool para consultas/métricas e create_skill para tarefas compostas; não recusar o objectivo só pelos tipos de create_tool.
- RF-02: describe_tools mostra nomes, descrições e schemas apenas de ferramentas disponíveis ao utilizador e show_ui; pode pedir uma pelo nome. Não executa as ferramentas nem expõe as desactivadas/restritas.
- RF-03: validar chamadas contra JSON Schema antes do handler, sem coerção nem efeitos laterais em entradas inválidas; devolver erro legível que permita corrigir obrigatórios, enum, tipos e limites, incluindo campos aninhados. Não consultar referências remotas.
- RF-04: create_skill rejeita dependências desconhecidas e exemplos de chamadas inválidos; aceita exemplos JSON opcionais sem executá-los e conserva os exemplos validados nas instruções. Sem migrations.
- RF-05: ao carregar uma skill, anexar os schemas actuais das suas dependências disponíveis, como autoridade sobre instruções antigas. Não conceder novas permissões.
- RF-06: manter confirmação humana, compatibilidade com ferramentas existentes e UI de perguntas compacta.

## Aceitação
Criação de um workflow de comparação chega a confirmação sem exigir um candidato agora. Um exemplo por=localizacao é rejeitado quando o enum é nome/cargo/formacao/tudo; localizacao continua parâmetro separado. A chamada inválida nunca executa o handler. Exemplos servem de contrato, não provam a validade semântica de toda a prosa de uma skill.

## Validação
Pest isolado em SQLite, suite existente, smoke UI e criação real de skill com confirmação no portal local e modelo configurado. Sem alterações de produção, sem publicação/commits.

## Evidência final
- 254 testes Pest, 983 assertions, aprovados; Pint e git diff --check aprovados. Os 9 testes Node da UI também passaram.
- E2E no portal local com o modelo real: pedido para criar resumo/comparação de vagas chegou directamente a uma proposta de skill sem pedir vagas concretas. Confirmação no browser mostrou Feito e Saved and enabled. Conta, conversas e skill de teste removidas no fim.
- Dependência instalada no portal local e ficheiros PHP do pacote sincronizados. Sem migrations, commits, releases ou deploy de produção.
- Exemplos de chamadas são opcionais; a validação estrutural dos exemplos não certifica toda a prosa. A validação dos argumentos reais mantém-se obrigatória antes de executar handlers.
