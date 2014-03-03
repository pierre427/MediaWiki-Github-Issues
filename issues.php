<?php
require_once(dirname(__FILE__).'/php-markdown/Michelf/MarkdownExtra.inc.php');
use \Michelf\MarkdownExtra;

$wgExtensionFunctions[] = "wikiGithubIssues";
$wgExtensionCredits['parserhook'][] = array(
	'name' => 'Github Issues',
	'author' => 'Aaron Parecki',
	'description' => 'Adds <nowiki><githubissues src=""></nowiki> tag to embed github issues in the wiki',
	'url' => 'https://github.com/aaronpk/MediaWiki-Github-Issues'
);

function wikiGithubIssues() {
    global $wgParser;    
    $wgParser->setHook("githubissues", "embedGithubIssues");
}

function embedGithubIssues($input, $args) {
	global $wgParser;
	$wgParser->disableCache();
	ob_start();

	if(!array_key_exists('src', $args)) {
		echo 'Error! Usage: <githubissues src="https://github.com/aaronpk/p3k/issues?labels=priority%3Aitching">';
	} else {
		if(preg_match('/https:\/\/github.com\/([^\/]+)\/([^\/]+)\/issues(\?.+)?/', $args['src'], $match)) {
		
			$username = $match[1];
			$repo = $match[2];
			
			// Prime the memcache lookup
			$meminstance = new Memcache();
			$meminstance->connect('localhost', 11211); 
			$querykey = "github_cache:" . md5(implode($args));
			$memhit = $meminstance->get($querykey);

			// Fetch the html from the memcache, or else generate and set it.
			if ( @$memhit != NULL ) {
				echo $memhit;
			} else {
				// Setup the curl API fetch
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, 'https://api.github.com/repos/'.$username.'/'.$repo.'/issues');
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				//curl_setopt($ch, CURLOPT_PROXY, "1.2.3.4:3128");
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_USERAGENT, 'MediaWiki Github Issues Extension');
				// Execute the fetch
				$response = curl_exec($ch);
				
				// If we get a response, proceed.
				if($response) {
					$issues = json_decode($response, TRUE);
					// If there were any issues for this project, display them in a table.
					if($issues) {
						$html = "<table class=\"wikitable\"><tbody>\n"; // Open the table
						$html .= "<tr><th>Issue ID</th>\n";
						$html .= "<th>State</th>\n";
						$html .= "<th>Milestone</th>\n";
						$html .= "<th>Updated Date</th>\n";
						$html .= "<th>Closed Date</th>\n";
						$html .= "<th>Description</th>\n";
						$html .= "</tr>\n";

						// For each issue, draw the row containing relevant values.
						foreach($issues as $issue) {

							$html .= "<tr><td><a href=\"" . $issue['html_url'] . "\">" . $issue['number'] . "</a></td>\n";
							$html .= "<td>" . strtoupper($issue['state']) . "</td>\n";
							if ( isset($issue['milestone']['title']) ) { $html .= "<td>" . $issue['milestone']['title'] . "</td>\n"; } else { $html .= "<td></td>"; }
							if ( isset($issue['updated_at']) ) { $html .= "<td>" . $issue['updated_at'] . "</td>\n"; } else { $html .= "<td></td>"; }
							if ( isset($issue['closed_at']) ) { $html .= "<td>" . $issue['closed_at'] . "</td>\n"; } else { $html .= "<td></td>"; }
							$html .= "<td>" . $issue['title'] . "</td></tr>\n";

						}
						
						$html .= "</tbody></table>\n"; // Close the table
						$meminstance->set($querykey, $html, 0, 3600); // Set the contents of the memcache

						echo $html; // Display the output
					} else {
						// We got a response, but no issues.
						echo '<p>JSON was returned from the API, but missing any issues.</p>';
						echo '<p>URL is: <a href="' . $args['src'] . '">' . $args['src'] . '</a></p>';
						echo "<p>JSON output: <br><pre>" . var_dump($response) . "</pre></p>";
					}
				} else {
					// No JSON result. Failed to connect or no input.
					echo 'Error retrieving content from GitHub, failed to fetch a JSON response.<br/>\n';
					echo '<p>URL is: <a href="' . $args['src'] . '">' . $args['src'] . '</a></p>';
				}
			}
		} else {
			echo 'Error! src must be a URL like the following: https://github.com/aaronpk/p3k/issues?labels=priority%3Aitching<br/>\n';
		}
	}
	return array(ob_get_clean(), 'noparse' => true, 'isHTML' => true);
}
