<?php
class Lifestream_LastFMFeed extends Lifestream_Feed
{
	const ID	= 'lastfm';
	const NAME	= 'Last.fm';
	const URL	= 'http://www.last.fm/';
	const LABEL	= 'Lifestream_ListenSongLabel';
	
	function __toString()
	{
		return $this->options['username'];
	}
	
	function get_event_display(&$event, &$bit)
	{
		return $bit['name'] . ' - ' . $bit['artist'];
	}
		
	function get_options()
	{		
		return array(
			'username' => array($this->lifestream->__('Username:'), true, '', ''),
			'loved' => array($this->lifestream->__('Only show loved tracks.'), false, true, true),
		);
	}
	
	function get_public_url()
	{
		return 'http://www.last.fm/user/'.$this->options['username'];
	}

	function get_url()
	{
		if ($this->options['loved'])
		{
			$feed_name = 'recentlovedtracks';
		}
		else
		{
			$feed_name = 'recenttracks';
		}
		
		return 'http://ws.audioscrobbler.com/1.0/user/'.$this->options['username'].'/'.$feed_name.'.xml';
	}
	
	function yield($track, $url)
	{
		return array(
			'guid'	  =>  $this->lifestream->html_entity_decode($track->url),
			'date'	  =>  strtotime($track->date),
			'link'	  =>  $this->lifestream->html_entity_decode($track->url),
			'name'	  =>  $this->lifestream->html_entity_decode($track->name),
			'artist'	=>  $this->lifestream->html_entity_decode($track->artist),
		);
	}
	
	function fetch()
	{
		$response = $this->lifestream->file_get_contents($this->get_url());
		if ($response)
		{
			$xml = new SimpleXMLElement($response);
			
			$feed = $xml->track;
			$items = array();
			foreach ($feed as $track)
			{
				$items[] = $this->yield($track, $url);
			}
			return $items;
		}
	}
	
	function render_item($row, $item)
	{
		return $this->lifestream->get_anchor_html(htmlspecialchars($item['artist']).' &#8211; '.htmlspecialchars($item['name']), $item['link']);
	}
}
$lifestream->register_feed('Lifestream_LastFMFeed');
?>