<?php

declare(strict_types=1);

namespace Drupal\kit_assistant\Controller;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\kit_assistant\Assistant;
use Drupal\kit_assistant\ConversationStore;
use Drupal\kit_assistant\OpenAiException;
use Drupal\kit_catalog\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * JSON endpoints for the chat widget: one message in, one reply out.
 *
 * Deliberately not a Form API form -- the widget is a plain fetch() POST
 * from js/chat.js, not a page reload/AJAX-framework round trip, so a
 * lightweight controller + manual CSRF/flood checks fit better here than
 * wiring a FormBase for a single text field.
 */
class ChatController extends ControllerBase {

  protected const MAX_MESSAGE_LENGTH = 1000;
  protected const FLOOD_EVENT = 'kit_assistant.message';
  protected const FLOOD_LIMIT = 30;
  protected const FLOOD_WINDOW = 3600;

  public function __construct(
    protected readonly Assistant $assistant,
    protected readonly ConversationStore $conversationStore,
    protected readonly AccountProxyInterface $account,
    protected readonly FloodInterface $flood,
    protected readonly CsrfTokenGenerator $csrfToken,
    protected readonly CartManager $cartManager,
    protected readonly SessionInterface $session,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kit_assistant.assistant'),
      $container->get('kit_assistant.conversation_store'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('csrf_token'),
      $container->get('kit_catalog.cart_manager'),
      $container->get('session'),
    );
  }

  /**
   * Issues a CSRF token for the widget, starting a session if needed.
   *
   * A plain top-level request/response -- unlike the block that renders
   * the widget shell, nothing here runs as a BigPipe placeholder, so a
   * session started while generating the token is guaranteed to still be
   * open when this response's headers (including Set-Cookie) go out. See
   * ChatWidgetBlock's docblock for why that distinction matters.
   *
   * Starting the session alone is not enough, though:
   * SessionManager::getSessionDataMask() explicitly ignores the metadata
   * bag ("it does not contain any user data") when deciding whether a
   * session is worth persisting -- and the CSRF token seed lives in
   * exactly that bag. Writing only a token, with nothing else in the
   * session, is therefore treated as an empty/obsolete session and never
   * actually gets a Set-Cookie, by design (so an anonymous visitor who
   * never needs a real session still stays cacheable). Setting one real
   * attribute-bag value is what makes the session -- and the seed riding
   * along with it -- actually persist.
   */
  public function token(): JsonResponse {
    $this->session->set('kit_assistant_active', TRUE);
    return new JsonResponse(['token' => $this->csrfToken->get()]);
  }

  /**
   * Handles one POSTed visitor message and returns Cleiton's reply.
   */
  public function message(Request $request): JsonResponse {
    if (!$this->config('kit_assistant.settings')->get('enabled')) {
      return new JsonResponse(['error' => 'disabled'], 503);
    }

    if (!$this->validCsrfToken($request)) {
      return new JsonResponse(['error' => 'invalid_token'], 403);
    }

    $identifier = $this->account->isAuthenticated()
      ? 'uid:' . $this->account->id()
      : 'ip:' . $request->getClientIp();
    if (!$this->flood->isAllowed(self::FLOOD_EVENT, self::FLOOD_LIMIT, self::FLOOD_WINDOW, $identifier)) {
      return new JsonResponse(['error' => 'rate_limited'], 429);
    }

    $data = json_decode($request->getContent(), TRUE);
    $message = is_array($data) ? ($data['message'] ?? NULL) : NULL;
    if (!is_string($message) || trim($message) === '') {
      return new JsonResponse(['error' => 'invalid_input'], 400);
    }
    $message = trim($message);
    if (mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
      return new JsonResponse(['error' => 'message_too_long'], 400);
    }

    $this->flood->register(self::FLOOD_EVENT, self::FLOOD_WINDOW, $identifier);

    $uid = $this->account->isAuthenticated() ? (int) $this->account->id() : NULL;

    try {
      $result = $this->assistant->respond($message, $uid);
    }
    catch (OpenAiException $e) {
      $this->getLogger('kit_assistant')->error('Assistant turn failed: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'reply' => 'Desculpe, o assistente está indisponível no momento. Tente novamente em instantes.',
        'login_required' => FALSE,
        'cart_count' => NULL,
      ]);
    }

    return new JsonResponse([
      'reply' => $result['reply'],
      'login_required' => $result['login_required'],
      'cart_count' => $uid !== NULL ? $this->cartManager->getItemCount($uid) : NULL,
    ]);
  }

  /**
   * Clears the current visitor's conversation history.
   */
  public function reset(Request $request): JsonResponse {
    if (!$this->validCsrfToken($request)) {
      return new JsonResponse(['error' => 'invalid_token'], 403);
    }
    $this->conversationStore->reset();
    return new JsonResponse(['status' => 'ok']);
  }

  /**
   * Validates the X-CSRF-Token header against the visitor's own session.
   */
  protected function validCsrfToken(Request $request): bool {
    $token = $request->headers->get('X-CSRF-Token', '');
    return is_string($token) && $token !== '' && $this->csrfToken->validate($token);
  }

}
