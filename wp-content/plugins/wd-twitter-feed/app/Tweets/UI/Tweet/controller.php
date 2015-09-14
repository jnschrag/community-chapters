<?php
/**
 * @package    twitterfeed
 * @date       Thu Aug 20 2015 10:24:31
 * @version    2.1.0
 * @author     Askupa Software <contact@askupasoftware.com>
 * @link       http://products.askupasoftware.com/twitter-feed/
 * @copyright  2015 Askupa Software
 */

namespace TwitterFeed\Tweets\UI;

/**
 * Implements a single tweet controller. Used by tweet lists objects.
 */
class Tweet extends \Amarkal\Template\Controller
{
    protected $tweet;
    
    protected $params;
    
    public function __construct( $tweet, $params )
    {
        global $twitterfeed_options;
        $this->tweet  = $tweet;
        $this->params = $params;
        $this->intent = 'https://twitter.com/intent/';
        $this->expand_media = $twitterfeed_options['expand_media'] === 'ON';
    }
    
    /**
     * Get the path to the template (script).
     * @return string    The path.
     */
    public function get_script_path() 
    {
        return dirname( __FILE__ ) . '/template.phtml';
    }
}
