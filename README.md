# WPMUDEV Test Plugin #

This is a plugin that can be used for testing coding skills for WordPress and PHP.

# Development

## Composer
Install composer packages
`composer install`

## Build Tasks (npm)
Everything should be handled by npm.

Install npm packages
`npm install`

| Command              | Action                                                |
|----------------------|-------------------------------------------------------|
| `npm run watch`      | Compiles and watch for changes.                       |
| `npm run compile`    | Compile production ready assets.                      |
| `npm run build`  | Build production ready bundle inside `/build/` folder |

## Composer & PHP dependencies
- Execute `composer install` during development to pull both runtime and dev dependencies.  
- Release builds should run `composer install --no-dev --optimize-autoloader` before `npm run build` so the generated zip only contains production code.  
- Composer is configured with `classmap-authoritative` and the plugin's autoloader is re-registered without prepending, ensuring our vendor classes never override another plugin's copy of the same dependency.

## Running PHPUnit tests
1. Install the WordPress test suite (only needs to happen once per environment):
   ```
   bin/install-wp-tests.sh wordpress_test root root db latest
   ```
   Adjust the DB credentials/host if you are running outside the Docker env.
2. Install dev dependencies and run the suite:
   ```
   composer install
   composer test
   ```
   The `composer test` script proxies to `vendor/bin/phpunit` and exercises the Posts Maintenance worker end-to-end.

## Posts Maintenance tools
- A nova página **Posts Maintenance** (menu principal) permite selecionar os post types públicos, definir o tamanho dos lotes e disparar um “Scan Posts” que roda em background (com feedback de progresso e próximas execuções agendadas).
- Um cron diário reutiliza o mesmo serviço para manter a meta `wpmudev_test_last_scan` atualizada mesmo sem intervenção manual.
- O comando WP-CLI `wp wpmudev scan-posts --post_types=post,page --batch-size=75` executa a mesma rotina em lote, exibindo barra de progresso e respeitando o limite configurável de lotes.

### WP-CLI (`wp wpmudev scan_posts`)
1. **Sintaxe básica**  
   ```
   wp wpmudev scan_posts --post_types=post,page --batch-size=75
   ```
   - `--post_types` (opcional): lista separada por vírgulas; quando omitida usa os defaults (`post,page` ou o que estiver disponível/publicado no seu site).
   - `--batch-size` (opcional): tamanho do lote processado a cada iteração, entre 10 e 200 (padrão 50).

2. **Execução como root**  
   - O WP-CLI exige `--allow-root` se você estiver rodando como root:  
     `wp wpmudev scan_posts --allow-root`.
   - Recomendado rodar como o usuário do WordPress (`sudo -u www-data -i -- wp ...`) para reduzir riscos.

3. **Condições para rodar**  
   - O serviço bloqueia execuções simultâneas: se um job estiver em andamento ou corrompido, o comando retornará “A scan is already running”. Nesse caso, aguarde a conclusão ou limpe o option `wpmudev_posts_scan_job`.
   - Cron job diário (hook `wpmudev_posts_scan_cron`) aproveita a mesma fila; não há necessidade de pausar o cron para rodar o CLI.

4. **Saída e progresso**  
   - Exibe barra `Scanning posts` com total baseado na fila atual.
   - Emite `success` ao final indicando quantos posts foram processados e os tipos incluídos.
