pbjx-bundle-php
=============

Symfony bundle that integrates [gdbots/pbjx](https://github.com/gdbots/pbjx-php) library.


# Configuration
Follow the standard [bundle install](http://symfony.com/doc/current/bundles/installation.html) using
__gdbots/pbjx-bundle__ as the composer package name.  The default configuration provides in memory
processing for all send, publish, request operations.  The EventStore and EventSearch are not
configured by default.

> The examples below assume you're running the DynamoDb EventStore and Elastica EventSearch.
> These are optional configurations.

__config/packages/pbjx.yml:__

```yaml
# many of the configurations below are defaults so you can remove them
# from your configuration, added here for reference

parameters:
  # optionally defined as parameter here to allow reuse
  es_clusters:
    default:
      debug: '%kernel.debug%'
      timeout: 300 # default
      persistent: true # default
      round_robin: true # default
      servers:
        - {host: '127.0.0.1', port: 9200} # default

gdbots_pbjx:
  # ensure tamper proof messaging by signing all pbjx messages
  # e.g. AWS Lambda to your API servers (signed with shared secret)
  # or by using bearer tokens in SPA to sign messages
  pbjx_token_signer:
    default_kid: '%env(PBJX_CURRENT_SIGNING_KID)%'
    # multiple keys allow for seamless key rotation
    keys:
      - {kid: '%env(PBJX_NEXT_SIGNING_KID)%', secret: '%env(PBJX_NEXT_SIGNING_SECRET)%'}
      - {kid: '%env(PBJX_CURRENT_SIGNING_KID)%', secret: '%env(PBJX_CURRENT_SIGNING_SECRET)%'}
  pbjx_controller:
    # if accepting commands from web trackers (analytics, click tracking, etc.)
    # then you may want to enable "GET" requests
    allow_get_request: false # default
    # array of curies or regex patterns that will not require x-pbjx-token validation
    bypass_token_validation: ['gdbots:pbjx:request:echo-request'] # default
  # the receive controller accepts transport messages.  it is ideally secured
  # in a VPC and only called by internal services.
  pbjx_receive_controller:
    enabled: false # default, ensure pbjx_token_signer.keys are set prior to enabling
  command_bus:
    transport: ~ # in_memory, firehose, kinesis
  event_bus:
    transport: ~ # in_memory, firehose, kinesis
  request_bus:
    # requests must return a value, so firehose and kinesis simply run
    # the request in memory as they don't support request/response.
    transport: ~ # in_memory
  event_store:
    provider: dynamodb
    dynamodb:
      table_name: acme-event-store
  event_search:
    provider: elastica
    elastica:
      # your app will at some point need to customize the queries
      # override the class so you can provide these customizations.
      class: Acme\Pbjx\EventSearch\Elastica\ElasticaEventSearch
      query_timeout: '500ms' # default
      clusters: '%es_clusters%'
      index_manager:
        # to customize index mapping
        class: Acme\Pbjx\EventSearch\Elastica\IndexManager
  scheduler:
    provider: dynamodb
    dynamodb:
      table_name: acme-scheduler
      state_machine_arn: 'arn:aws:states:%cloud_region%:%aws_account_id%:stateMachine:acme-%app_env%-pbjx-scheduler'

# typically these would be in services.yml file.
services:
  # unless you're starting with dynamodb streams publishing events you will need this.
  gdbots_pbjx.event_store.dynamodb_2pc:
    class: Gdbots\Pbjx\EventStore\TwoPhaseCommitEventStore
    decorates: gdbots_pbjx.event_store.dynamodb
    arguments:
      - '@pbjx'
      - '@gdbots_pbjx.event_store.dynamodb_2pc.inner'
      - '%env(PBJX_DISABLE_2PC_EVENT_STORE)%'
    public: false

  # If you are using AWS ElasticSearch service, use AwsAuthV4ClientManager
  # UPDATE as of November 2017, AWS supports ES in a VPC; this is preferable
  # to using IP rules and/or AwsAuthV4ClientManager.
  gdbots_pbjx.event_search.elastica.client_manager:
    class: Gdbots\Pbjx\EventSearch\Elastica\AwsAuthV4ClientManager
    public: true
    arguments:
      - '@aws_credentials'
      - '%cloud_region%'
      - '%es_clusters%'
      - '@logger'
    tags:
      - {name: monolog.logger, channel: pbjx.event_search}
```

> In your local environment, it is highly recommended to configure the PbjxDebugger.

__config/services_local.yml:__

```yaml
services:
  monolog_json_formatter:
    class: Monolog\Formatter\JsonFormatter
    arguments: ['!php/const:Monolog\Formatter\JsonFormatter::BATCH_MODE_NEWLINES']

monolog:
  handlers:
    pbjx_debugger:
      type: stream
      path: '%kernel.logs_dir%/pbjx-debugger.log'
      level: debug
      formatter: monolog_json_formatter
      channels: ['pbjx.debugger']
```


# Pbjx HTTP Endpoints
Pbjx is ready to be used within your app and console commands but it's not yet available via HTTP.
__Providing the HTTP features is very powerful but can be very dangerous if you don't secure it correctly.__

All of the usual rules apply when securing your app.  Authentication and authorization is up to you, however,
Symfony makes this fairly easy using the [security components](http://symfony.com/doc/current/components/security.html).

__Example security configuration:__

```yaml
# see http://symfony.com/doc/current/security/voters.html
pbjx_permission_voter:
  class: App\Security\PbjxPermissionVoter
  public: false
  arguments: ['@security.access.decision_manager']
  tags:
    - {name: security.voter}

# see http://symfony.com/doc/current/components/security/authorization.html#access-decision-manager
# use the Gdbots\Bundle\PbjxBundle\Validator\PermissionValidatorTrait to provide some boilerplate.
gdbots_pbjx.pbjx_permission_validator:
  class: AppBundle\Security\PbjxPermissionValidator
  public: false
  arguments: ['@request_stack', '@security.authorization_checker']
  tags:
    - {name: pbjx.event_subscriber}
```

To enable Pbjx http endpoints you must include the routes.  In __config/routes.yml__:

```yaml
pbjx:
  resource: '@GdbotsPbjxBundle/Resources/config/routes.xml'
  prefix: /pbjx
```

Once this is in place __ANY__ pbjx messages can be sent to the endpoint `/pbjx/vendor/package/category/message`.
This url is the configured prefix and then the `SchemaCurie` resolved to a url.

> Why not just use `/pbjx`?  It is a huge benefit to have the full path to the `SchemaCurie` for logging,
> authorization, load balancing, debugging, etc.

__Example curl request:__

```bash
curl -X POST -s -H "Content-Type: application/json" "https://yourdomain.com/pbjx/gdbots/pbjx/request/echo-request" -d '{"msg":"test"}'

# with PbjxToken signature (use composer package gdbots/pbjx or npm package @gdbots/pbjx to create tokens)
curl -X POST -s -H "Content-Type: application/json" -H "x-pbjx-token: TOKENHERE" "https://yourdomain.com/pbjx/acme/blog/command/publish-article" -d '{"id":"123"}'
```

__Example ajax request:__

```javascript
$.ajax({
  url: '/pbjx/gdbots/pbjx/request/echo-request',
  type: 'post',
  contentType: 'application/json; charset=utf-8',
  dataType: 'json',
  data: JSON.stringify({msg: 'hello'}),
  complete: function (xhr) {
    console.log(xhr.responseJSON);
  }
});
```

> If your `SchemaCurie` contains an empty category segment, use "_" in its place in the url.


# Controllers
The recommended way to use Pbjx in a controller is to use Symfony dependency injection for
the pbjx services you need and if necessary import the `PbjxControllerTrait` to get
helper methods for rendering pbj into twig templates.


```php
final class ArticleController extends Controller
{
    use PbjxControllerTrait;

    /** @var Pbjx */
    private $pbjx;

    /**
     * @param Pbjx $pbjx
     */
    public function __construct(Pbjx $pbjx)
    {
        $this->pbjx = $pbjx;
    }

    /**
     * @Route("/articles/{article_id}", requirements={"article_id": "^[0-9A-Fa-f]+$"})
     * @Method("GET")
     * @Security("is_granted('acme:blog:request:get-article-request')")
     *
     * @param Request $request
     *
     * @return Response
     */
    public function getAction(Request $request): Response
    {
        $getArticleRequest = GetArticleRequestV1::create()->set('article_id', $request->attributes->get('article_id'));
        $getArticleResponse = $this->pbjx->request($getArticleRequest);
        return $this->renderPbj($getArticleResponse);
    }
}
```

### PbjxControllerTrait::renderPbj
This is a convenience method that accepts a pbj message and derives the template name
using `pbjTemplate` and calls Symfony's `render` method (from framework bundle `Controller`).

The template will have `pbj` as a variable which is the message object itself.

> __TIP:__ {{ pbj }} will dump the message to yaml for easy debugging in twig,
> or {{ pbj|json_encode(constant('JSON_PRETTY_PRINT')) }}

### PbjxControllerTrait::pbjTemplate
Returns a reference to a twig template based on the schema of the provided message (pbj schema).
This allows for component style development for pbj messages.  You are asking for a template that
can render your message (e.g. Article) as a "card", "modal", "page", etc.

> This can be combined with __gdbots/app-bundle__ `DeviceViewRendererTrait::renderUsingDeviceView`
> _(the renderPbj method does this automatically)_.

What you end up with is a [namespaced path](http://symfony.com/doc/current/templating/namespaced_paths.html)
reference to a template which conforms to the [Symfony template naming best practices](http://symfony.com/doc/current/best_practices/templates.html#template-locations).
Examples:

<table>
<tr>
  <th>SchemaCurie</th>
  <th>Template</th>
  <th>Format</th>
  <th>Twig Paths</th>
</tr>
<tr>
  <td>acme:blog:node:article</td>
  <td>page</td>
  <td>html</td>
  <td>@acme_blog/node/article/page.html.twig</td>
</tr>
<tr>
  <td>acme:users:request:search-users-response</td>
  <td>page</td>
  <td>json</td>
  <td>@acme_users/request/search_users_response/page.json.twig</td>
</tr>
<tr>
  <td>acme:users:node:user</td>
  <td>card</td>
  <td>html</td>
  <td>@acme_users/node/user/card.html.twig</td>
</tr>
</table>


# Twig Extension
A few twig functions are provided to expose most of what controllers can do to your twig templates.

### Twig Function: pbj_template
Returns a reference to a twig template based on the schema of the provided message (pbj schema).
This allows for component style development for pbj messages.  You are asking for a template that
can render your message (e.g. Article) as a "card", "modal", "slack_post", etc. and optionally
that template can be device view specific _(card.smartphone.html.twig)_.

__Example:__

```txt
{% include pbj_template(pbj, 'card', 'html', device_view) with {'pbj': pbj} %}
```

### Twig Function: pbj_url
Returns a named URL to a pbj instance. This depends on `gdbots/uri-template` package which provides a way
to register uri templates. These are expected to be in the format `vendor:label.template_name`, e.g. `acme:article.canonical`.

__Example:__

```txt
{{ pbj_url(pbj, 'canonical') }}
```

### Twig Function: uri_template_expand
When you need to expand a URI template by id direct with your own variables, use this function.

__Example:__

```txt
{{ uri_template_expand('acme:article.canonical', {slug: 'some-slug'}) }}
```

### Twig Function: pbjx_request
In the same way that you can [embed a Symfony controller within twig](https://symfony.com/doc/current/templating/embedding_controllers.html)
you can embed a pbjx request in twig.  This function performs a `$pbjx->request($request);`
and returns the response.  If debugging is enabled an exception will be thrown
(generally in dev), otherwise it will be logged and null will be returned.

__Example:__

```txt
{% set get_comments_response = pbjx_request('acme:blog:request:get-comments-request', {'article_id': id}) %}
{% if get_comments_response %}
  {% include pbj_template(get_comments_response, 'list', device_view) with {'pbj': get_comments_response} %}
{% endif %}
```


# Console Commands
This library provides quite a few commands to make managing the services of Pbjx simple.
Run the Symfony console and look for __pbjx__ commands.

```txt
pbjx                                [pbjx:message] Handles pbjx messages (command, event, request) and returns an envelope with the result.
pbjx:batch                          [pbjx:lines] Reads messages from a newline-delimited JSON file and processes them.
pbjx:create-event-search-storage    Creates the EventSearch storage.
pbjx:create-event-store-storage     Creates the EventStore storage.
pbjx:create-scheduler-storage       Creates the Scheduler storage.
pbjx:describe-event-search-storage  Describes the EventSearch storage.
pbjx:describe-event-store-storage   Describes the EventStore storage.
pbjx:describe-scheduler-storage     Describes the Scheduler storage.
pbjx:export-events                  Pipes events from the EventStore to STDOUT.
pbjx:reindex-events                 Pipes events from the EventStore and reindexes them.
pbjx:replay-events                  Pipes events from the EventStore and replays them through pbjx->publish.
pbjx:tail-events                    Tails events from the EventStore for a given stream id and writes them to STDOUT.
```

The most useful is probably going to be the __pbjx__ and __pbjx:batch__ commands.
These run pbjx just like you do in your application code and return the resulting pbj.

> Pbjx is designed to run the the same way via cli, application code and http.

```bash
console pbjx --pretty 'gdbots:pbjx:request:echo-request' '{"msg":"hello"}'
```

__Example response:__

```json
{
    "_schema": "pbj:gdbots:pbjx::envelope:1-0-0",
    "envelope_id": "5d87da8b-b3b5-4e2f-9f60-843e79b678dc",
    "ok": true,
    "code": 0,
    "http_code": 200,
    "etag": null,
    "message_ref": {
        "curie": "gdbots:pbjx:request:echo-response",
        "id": "aacc60a0-92a5-4ee6-9aec-63b149abcf1d"
    },
    "message": {
        "_schema": "pbj:gdbots:pbjx:request:echo-response:1-0-0",
        "response_id": "aacc60a0-92a5-4ee6-9aec-63b149abcf1d",
        "created_at": "1488149039527239",
        "ctx_request_ref": {
            "curie": "gdbots:pbjx:request:echo-request",
            "id": "c6867d0c-0c97-4e27-903f-30bbd69da79c"
        },
        "ctx_request": {
            "_schema": "pbj:gdbots:pbjx:request:echo-request:1-0-0",
            "request_id": "c6867d0c-0c97-4e27-903f-30bbd69da79c",
            "occurred_at": "1488149039229879",
            "ctx_retries": 0,
            "ctx_correlator_ref": {
                "curie": "gdbots:pbjx::envelope",
                "id": "5d87da8b-b3b5-4e2f-9f60-843e79b678dc"
            },
            "ctx_app": {
                "_schema": "pbj:gdbots:contexts::app:1-0-0",
                "vendor": "acme",
                "name": "blog-php.console",
                "version": "v0.1.0",
                "build": "1488148956"
            },
            "ctx_cloud": {
                "_schema": "pbj:gdbots:contexts::cloud:1-0-0",
                "provider": "private",
                "region": "us-west-2",
                "zone": "us-west-2a",
                "instance_id": "123456",
                "instance_type": "vbox"
            },
            "ctx_ip": "127.0.0.1",
            "ctx_ua": "pbjx-console\/0.x",
            "msg": "hello"
        },
        "ctx_correlator_ref": {
            "curie": "gdbots:pbjx::envelope",
            "id": "5d87da8b-b3b5-4e2f-9f60-843e79b678dc"
        },
        "msg": "hello"
    }
}
```

Review the `--help` on the pbjx commands for more details.



# Library Development
Pbj has a concept of [mixins](https://github.com/gdbots/pbjc-php) which is just a schema that can
be added to other schemas.  This strategy is useful for creating consistent data structures and
allowing for library development to be done against mixins and not concrete schemas.

> A mixin cannot be used by itself to create messages, it must be added to a schema that is NOT a mixin.

This bundle provides a [compiler pass](http://symfony.com/doc/current/service_container/compiler_passes.html)
that automatically registers handlers for pbjx command and requests.

__Example:__

> Fictional __WidgetCo__ makes widgets for websites by creating mixins and libraries to
> provide implementations for those mixins.

- WidgetCo has a mixin called `widgetco:blog:mixin:add-comment`
- WidgetCo has a handler called `WidgetCo\Blog\AddCommentHandler` that uses marker interface `Gdbots\Pbjx\DependencyInjection\PbjxHandler`.
- WidgetCo's handler uses the mixins `findOne` or `findAll` method to return all `SchemaCurie` objects it can handle.

> Your company __Acme__ now has a blog and wants to use __WidgetCo__ mixins _AND_ the implementation
> provided by __WidgetCoBlogBundle__.

- Acme creates a concrete schema called `acme:blog:command:add-comment` that uses the mixin `widgetco:blog:mixin:add-comment`
- When pbjx sends the command it will look for a service to handle `acme:blog:command:add-comment` curie.
- The service can be [autoconfigured by symfony](https://symfony.com/doc/current/service_container.html#the-autoconfigure-option)

You can still override if you want to extend or replace what widgetco provides by
implementing your own handler using the `PbjxHandler` interface.

> You can provide concrete schemas and implementations in libraries.
> There are pros and cons to both strategies, the biggest issue is that the schema
> is not as easily customized at the application level if the library is not
> developed using mixins.

__Pbjx__ itself is a library built on mixins for `Command`, `Request` and `Event` messages.
