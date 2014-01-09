<?php
/**
 * @package Newscoop
 * @copyright 2012 Sourcefabric o.p.s.
 * @license http://www.gnu.org/licenses/gpl-3.0.txt
 */

use Tageswoche\Mobile\OfflineIssueService;
use Newscoop\Image\Rendition;
use Newscoop\Entity\User;
use Newscoop\Entity\Article;
use Newscoop\Webcode\Manager;

/**
 */
abstract class AbstractController extends Zend_Controller_Action
{
    const DIGITAL_UPGRADE = '_digital_upgrade';

    const DATE_FORMAT = 'Y-m-d H:i:s';

    const CLIENT_DEFAULT = 'ipad';
    const VERSION_DEFAULT = '1.0';

    const FRONT_SIDE = 'front';
    const BACK_SIDE = 'back';

    const IMAGE_STANDARD_RENDITION = 'rubrikenseite';

    const IMAGE_STANDARD_WIDTH = 105;
    const IMAGE_STANDARD_HEIGHT = 70;
    const IMAGE_RETINA_FACTOR = 2;

    const AD_WIDTH = 320;
    const AD_HEIGHT = 70;

    const FACEBOOK_AUTH_TOKEN = 'fb_access_token';

    const AD_TYPE = 'iPad_Ad';

    /** @var int */
    private $rank = 1;

    /** @var array */
    private $fields = array(
        'teaser' => array(
            'newswire' => 'DataLead',
            'blog' => 'lede',
        ),
        'social_teaser' => array(
            'newswire' => 'DataLead',
            'blog' => 'lede',
            'news' => 'lede',
            'dossier' => 'lede',
            'eventnews' => 'lede',
            'event' => 'description'
        ),
    );

    /** @var array */
    private $clientSize = array();

    protected $client;

    /**
     * Get user by username and password params
     *
     * @return Newscoop\Entity\User
     */
    protected function getUser()
    {
        if ($this->_getParam(self::FACEBOOK_AUTH_TOKEN)) {
            $user = $this->_helper->service('auth.adapter.facebook')->findByAuthToken($this->_getParam(self::FACEBOOK_AUTH_TOKEN));
            return $user !== null ? $user : $this->sendError('Invalid credentials', 412);
        }

        $username = $this->_getParam('username');
        $password = $this->_getParam('password');
        if (empty($username) || empty($password)) {
            $this->sendError('Invalid credentials.', 401);
        }

        $user = $this->_helper->service('auth.adapter')->findByCredentials($username, $password);
        return $user !== null ? $user : $this->sendError('Invalid credentials.', 401);
    }

    /**
     * Send error and exit
     *
     * @param string $body
     * @param int $code
     * @return void
     */
    protected function sendError($body = '', $code = 400)
    {
        $this->getResponse()->setHttpResponseCode($code);
        $this->_helper->json(array(
            'code' => $code,
            'message' => $body,
        ));

        $this->getResponse()->sendResponse($code);
    }

    /**
     * Assert request is secure
     *
     * @return void
     */
    protected function assertIsSecure()
    {
        if (APPLICATION_ENV === 'development' || $this->isAuthorized()) {
            return;
        }

        if (!$this->getRequest()->isSecure()) {
            $this->sendError('Secure connection required.');
        }
    }

    /**
     * Assert request is post
     *
     * @return void
     */
    protected function assertIsPost()
    {
        if (!$this->getRequest()->isPost()) {
            $this->sendError('POST required.');
        }
    }

    /**
     * Get client and version params
     *
     * @param bool $onlyParams
     * @return string
     */
    protected function getClientVersionParams($onlyParams = true)
    {
        return sprintf('%sclient=%s&version=%s', $onlyParams ? '?' : '&', $this->_getParam('client', 'ipad'), $this->_getParam('version', '1.0'));
    }

    /**
     * Assert that user is subscriber and can consume premium content
     *
     * @param Newscoop\Entity\Article $article
     * @return void
     */
    protected function assertIsSubscriber($article = null)
    {
        if ($this->isAuthorized()) {
            return;
        }

        if ($this->_getParam('receipt_data') && $this->_getParam('device_id')) {
            if ($this->_helper->service('mobile.purchase')->isValid($this->_getParam('receipt_data'))) {
                return;
            }
        }

        if ($this->hasAuthInfo() && ($user = $this->getUser())) {
            if ($this->_helper->service('subscription.device')->hasDeviceUpgrade($user, $this->_getParam('device_id'))) {
                return;
            } else {
                $this->sendError('Device limit reached', 409);
            }
        }

        if ($article !== null && ! $this->_helper->service('mobile.issue')->isInCurrentIssue($article)) {
            return;
        } elseif ($article !== null && $this->isAd($article)) {
            return;
        }

        $this->sendError('Unauthorized.', 401);
    }

    /**
     * Test if request is authorized
     *
     * @return bool
     */
    protected function isAuthorized()
    {
        $request = $this->getRequest();
        $options = $this->getInvokeArg('bootstrap')->getOption('offline');
        return !empty($options['secret']) && $request->getHeader(OfflineIssueService::OFFLINE_HEADER) === $options['secret'];
    }

    /**
     * Test if request has auth info
     *
     * @return bool
     */
    protected function hasAuthInfo()
    {
        return $this->_getParam('username') || $this->_getParam(self::FACEBOOK_AUTH_TOKEN);
    }

    /**
     * Get topic api url
     *
     * @param Newscoop\Entity\Topic $topic
     * @return string
     */
    protected function getTopicUrl($topic)
    {
        return $this->view->serverUrl($this->view->url(array(
            'module' => 'api',
            'controller' => 'articles',
            'action' => 'list',
        ), 'default') . $this->getApiQueryString(array(
            'topic_id' => $topic->getTopicId(),
        )));
    }

    /**
     * Get article api url
     *
     * @param Newscoop\Entity\Article $article
     * @param string $side
     * @param array $params
     * @return string
     */
    protected function getArticleUrl($article, $side = self::FRONT_SIDE, array $params = array())
    {
        $params['article_id'] = $article->getNumber();
        $params['side'] = $side;
        return $this->view->serverUrl($this->view->url(array(
            'module' => 'api',
            'controller' => 'articles',
            'action' => 'item',
        ), 'default') . $this->getApiQueryString($params));
    }

    /**
     * Get article comments api url
     *
     * @param mixed $article
     * @return string
     */
    protected function getCommentsUrl($article)
    {
        return $this->view->serverUrl($this->view->url(array(
            'module' => 'api',
            'controller' => 'comments',
            'action' => 'list',
        ), 'default') . $this->getApiQueryString(array(
            'article_id' => $article->getNumber(),
        )));
    }

    /**
     * Get api query string
     *
     * @param array $params
     * @return string
     */
    protected function getApiQueryString(array $params = array())
    {
        $params = array_filter(array_merge($params, array(
            'client' => $this->_getParam('client'),
            'version' => $this->_getParam('version'),
        )));

        return empty($params) ? '' : '?' . implode('&', array_map(function ($key) use ($params) {
            return sprintf('%s=%s', $key, $params[$key]);
        }, array_keys($params)));
    }

    /**
     * Get article dateline
     *
     * @param Newscoop\Entity\Article $article
     * @return string
     */
    protected function getDateline($article)
    {
        try {
            $dateline = ($article->getType() === 'blog')
                ? $article->getSectionName()
                : $article->getData('dateline');
            return !empty($dateline) ? $dateline : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get article website url
     *
     * @param Newscoop\Entity\Article $article
     * @return string
     */
    protected function getWebsiteUrl($article)
    {
        return $this->view->serverUrl() . '/' . Manager::getWebcoder('')->encode($article->getNumber());
    }

    /**
     * Get topics
     *
     * @param Newscoop\Entity\Article $article
     * @return array
     */
    protected function getTopics($article)
    {
        $topics = array();
        foreach ($article->getTopicNames() as $id => $name) {
            $topics[] = array(
                'topic_id' => $id,
                'topic_name' => $name,
            );
        }

        return $topics;
    }

    /**
     * Get article image
     *
     * @param Article $article
     * @return string $thumbnail
     */
    protected function getImage($article, $rendition)
    {
        $renditions = Zend_Registry::get('container')->getService('image.rendition')->getRenditions();
        if (!array_key_exists($rendition, $renditions)) {
            return null;
        }

        $articleRenditions = Zend_Registry::get('container')
            ->getService('image.rendition')->getArticleRenditions($article->getId());
        $articleRendition = $articleRenditions[$renditions[$rendition]];

        if ($articleRendition === null) {
            return null;
        }

        $thumbnail = $articleRendition->getRendition()->
            getThumbnail($articleRendition->getImage(), Zend_Registry::get('container')->getService('image'));

        return $thumbnail;
    }

    /**
     * Get article image url
     *
     * @param mixed $article
     * @param string $rendition
     * @param int $width
     * @param int $height
     * @return string
     */
    protected function getImageUrl($article, $rendition = self::IMAGE_STANDARD_RENDITION)
    {
        $image = $this->getImage($article, $rendition);
        if (empty($image)) {
            return null;
        }

        $imageUrl = $this->view->url(array(
            'src' => $this->getHelper('service')->getService('image')->getSrc(basename($image->src), $this->getClientWidth(), $this->getClientHeight(), 'crop'),
        ), 'image', false, false);

        return $this->view->serverUrl($imageUrl);
    }

    /**
     * Get ad image url
     *
     * @param mixed $ad
     * @param string $rendition
     * @return string
     */
    protected function getAdImageUrl(Article $ad)
    {
        $images = $this->getHelper('service')->getService('image')->findByArticle($ad->getNumber());
        foreach ($images as $image) {
            if ($image->getWidth() <= self::AD_WIDTH * 2) {
                return $this->view->serverUrl() . '/' .$image->getPath();
            }
        }

        return null;
    }

    /**
     * Get local image url
     *
     * @param object $image
     * @param array $normalSizes
     * @param array $retinaSizes
     * @return string
     */
    protected function getLocalImageUrl($image, array $normalSizes, array $retinaSizes)
    {
        if ($image === null) {
            return null;
        }

        list($width, $height) = $this->isRetinaClient() ? $retinaSizes : $normalSizes;
        $imageUrl = $this->view->url(array(
            'src' => $this->getHelper('service')->getService('image')->getSrc($image->getPath(), $width, $height, 'fit'),
        ), 'image', false, false);

        return $this->view->serverUrl($imageUrl);
    }

    /**
     * Get comments count
     *
     * @param mixed Article
     * @param bool $recommended
     * @return int
     */
    protected function getCommentsCount($article, $recommended = false)
    {
        $constraints = array('thread' => $article->getNumber());

        if ($recommended) {
            $constraints['recommended'] = 1;
        }

        return Zend_Registry::get('container')->getService('comment')->countBy($constraints);
    }

    /**
     * Format article for api
     *
     * @param mixed $article
     * @return array
     */
    protected function formatArticle($article)
    {
        $data = array(
            'article_id' => $article->getNumber(),
            'url' => $this->getArticleUrl($article),
            'backside_url' => $this->getArticleUrl($article, 'back'),
            'dateline' => $this->getDateline($article),
            'short_name' => $this->getShortname($article),
            'published' => $this->formatDate($article->getPublished()),
            'rank' => $this->rank++,
            'website_url' => $this->getWebsiteUrl($article),
            'image_url' => $this->getImageUrl($article),
            'comments_enabled' => $article->commentsEnabled() && !$article->commentsLocked(),
            'comment_count' => $this->getCommentsCount($article),
            'recommended_comment_count' => $this->getCommentsCount($article, true),
            'comment_url' => $this->getCommentsUrl($article),
            'topics' => $this->getTopics($article),
            'slideshow_images' => $this->_helper->renderSlideshow($article->getNumber()),
            'teaser' => $this->getTeaser($article),
            'facebook_teaser' => $this->getTeaser($article, 'social'),
            'twitter_teaser' => $this->getTeaser($article, 'social'),
            'link' => (bool) ($article->getType() == 'link')
        );
        
        if ($article->getType() == 'link') {
            $data['url'] = $article->getData('link_url');
        }

        if ($this->isAd($article)) {
            $data['advertisement'] = true;
            $data['image_url'] = $this->getAdImageUrl($article);
            $data['short_name'] = $this->getArticleField($article, 'ad_name') ? ucwords((string) $this->getArticleField($article, 'ad_name')) : 'Anzeige';
            $data['url'] = $this->getArticleField($article, 'hyperlink');
        }

        return $data;
    }

    /**
     * Format datetime
     *
     * @param DateTime $date
     * @return string
     */
    protected function formatDate(\DateTime $date)
    {
        $date->setTimezone(new DateTimeZone('Europe/Berlin'));
        return $date->format('Y-m-d H:i:s');
    }

    /**
     * Get teaser
     *
     * @param Newscoop\Entity\Article $article
     * @return string
     */
    protected function getTeaser($article, $option = false)
    {   
        if ($option == 'social') {
            try {
                $field = isset($this->fields['social_teaser'][$article->getType()])
                    ? $this->fields['social_teaser'][$article->getType()]
                    : 'teaser';
                return strip_tags(trim($article->getData($field)));
            } catch (\Exception $e) {
                return strip_tags(trim($article->getTitle()));
            }
        }

        try {
            $field = isset($this->fields['teaser'][$article->getType()])
                ? $this->fields['teaser'][$article->getType()]
                : 'teaser';
            return strip_tags(trim($article->getData($field)));
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Test if client is iphone
     *
     * @return bool
     */
    protected function isIphoneClient()
    {
        return strpos($this->_getParam('client', 'iphone'), 'iphone') !== false;
    }

    /**
     * Get client width
     *
     * @return int
     */
    protected function getClientWidth()
    {
        if (empty($this->clientSize)) {
            $this->initClientSize();
        }

        return $this->clientSize['width'];
    }

    /**
     * Get client height
     *
     * @return int
     */
    protected function getClientHeight()
    {
        if (empty($this->clientSize)) {
            $this->initClientSize();
        }

        return $this->clientSize['height'];
    }

    /**
     * Init client size
     *
     * @return void
     */
    private function initClientSize()
    {
        $this->clientSize = array(
            'width' => self::IMAGE_STANDARD_WIDTH,
            'height' => self::IMAGE_STANDARD_HEIGHT,
        );

        if ($this->isRetinaClient()) {
            $this->clientSize['width'] *= self::IMAGE_RETINA_FACTOR;
            $this->clientSize['height'] *= self::IMAGE_RETINA_FACTOR;
        }
    }

    /**
     * Test if client is retina
     *
     * @return bool
     */
    protected function isRetinaClient()
    {
        return strpos($this->_getParam('client', self::CLIENT_DEFAULT), 'retina') !== false;
    }

    /**
     * Get article shortname
     *
     * @param Newscoop\Entity\Article $article
     * @return string
     */
    protected function getShortname($article)
    {
        try {
            $shortname = $article->getData('short_name');
            return !empty($shortname) ? $shortname : $article->getTitle();
        } catch (\Exception $e) {
            return $article->getTitle();
        }
    }

    /**
     * Get article field
     *
     * @param Article $article
     * @param string $field
     * @return mixed
     */
    protected function getArticleField($article, $field)
    {
        try {
            return $article->getData($field);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Get rendition image url
     *
     * @param Newscoop\Entity\Article $article
     * @param string $rendition
     * @param array $normalSizes
     * @param array $retinaSizes
     * @return string
     */
    protected function getRenditionUrl($article, $rendition, array $normalSizes, array $retinaSizes)
    {
        list($width, $height) = $this->isRetinaClient() ? $retinaSizes : $normalSizes;
        $image = $this->_helper->service('image.rendition')->getArticleRenditionImage($article->getNumber(), $rendition, $width, $height);
        if (empty($image['src'])) {
            return null;
        }

        $src = Zend_Registry::get('view')->url(array('src' => $image['src']), 'image', true, false);
        return $this->view->serverUrl($src);
    }

    /**
     * Get user image url
     *
     * @param Newscoop\Entity\User $user
     * @param array $normalSizes
     * @param array $retinaSizes
     * @return string
     */
    protected function getUserImageUrl($user, array $normalSizes, array $retinaSizes)
    {
        $imageService = Zend_Registry::get('container')->getService('image');
        list($width, $height) = $this->isRetinaClient() ? $retinaSizes : $normalSizes;

        $src = Zend_Registry::get('view')->url(array('src' => $imageService->getUserImage($user, $width, $height)), 'image', true, false);

        if ($src === null) {
            return null;
        }

        return $this->view->serverUrl($src);
    }

    /**
     * Get client identification
     *
     * @return string
     */
    protected function getClient()
    {
        return strtolower($this->_getParam('client', self::CLIENT_DEFAULT));
    }

    /**
     * Test if article is advertisement
     *
     * @param Newscoop\Entity\Article $article
     * @return bool
     */
    protected function isAd($article)
    {
        return $article->getType() === self::AD_TYPE;
    }

    /**
     * Get list of ads (articleType == iPad_Ad)
     *
     * @param string switch
     * @return array
     */
    protected function getArticleListAds($switch = null)
    {
        $listAds = array();
        $ads = $this->_helper->service('article')->findBy(array('type' => self::AD_TYPE), array('articleOrder' => 'asc'));
        foreach ($ads as $ad) {
            try {
                if ($ad->getData('active')) {
                    if ($switch) {
                        if ($ad->getData($switch)) {
                            $listAds[] = $ad;
                        }
                    } else {
                        $listAds[] = $ad;
                    }
                }
            } catch (InvalidPropertyException $e) { // ignore
            }
        }

        return $listAds;
    }

    /**
     * Init client property
     *
     * @return void
     */
    protected function initClient($client)
    {
        $type = null;
        if (strstr($client, 'ipad')) {
            $type = 'ipad';
        } elseif (strstr($client, 'iphone')) {
            $type = 'iphone';
        }

        $this->client = array(
            'name' => $client,
            'type' => $type,
        );
    }
}
