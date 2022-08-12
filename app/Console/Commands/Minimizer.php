<?php

namespace App\Console\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\DomCrawler\Crawler;

class Minimizer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:minimizercss {url} {--list-class} {--list-ids} {--local}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'This command will minify the css code inside the external pages for can be created amp pages';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        $url = $this->argument('url');

        if (!$this->check_is_valid_url($url) && !$this->option('local')) {
            $this->error("{$url} is not a valid URL!");
            return 0;
        }

        if (!$this->check_is_live_url($url) && !$this->option('local')) {
            $this->error("{$url} is not live!");
            return 0;
        }

        // Get url content
        $html_original_content = file_get_contents($url);

        // Extract classes and ids from the html content
        $classes = $this->get_all_identifier($html_original_content, 'class');
        $ids = $this->get_all_identifier($html_original_content, 'id');
        $this->info('Extract classes and ids from the html content...');

         // Generate classes and ids from the html content
        $classes = $this->generate_new_short_names($classes);
        $ids = $this->generate_new_short_names($ids);

        $this->show_all_classs_and_ids($classes, $ids);

        // Replace new identifiers in the html content
        $html_result = $this->replace_identifier($html_original_content, $classes);
        $html_result = $this->replace_identifier($html_result, $ids);
        $this->info('Replace new identifiers in the html content...');

        // Set css with new identifiers on the css files
        $css_result = $this->extract_css_content($classes, 'class', $html_original_content);
        $css_result .= $this->extract_css_content($ids, 'id', $html_original_content);
        $this->info('Set css with new identifiers on the css files...');

        $html_result = $this->set_new_css_file($html_result);

        // Output the result files css, html and js
        file_put_contents('./output/index.css', $css_result);
        file_put_contents('./output/index.html', $html_result);
        $this->info('Output the result files css, html and js...');
        $this->replace_xpaths();
        $this->replace_identifier_in_js_files($html_original_content, $ids);

        $this->info('Process is Done!');

        return 0;
    }


    private function replace_identifier_in_js_files($html_original_content, $ids):void {
       $host_url = parse_url($this->argument('url'));
       $links = $this->get_js_link_files($html_original_content, $host_url);
       $links->each(function($link) use ($ids) {
       $link_content = '';
        try {
            $link_content = file_get_contents($link);

        } catch (\Exception $e) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $link);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HEADER, false);
            $link_content = curl_exec($curl);
            curl_close($curl);
        }

           $link_content = $this->replace_identifier($link_content, $ids);
           file_put_contents('./output/'. Str::of($link)->basename(), $link_content);
       });
    }


    protected function get_js_link_files($html_original_content, $host_url): Collection {
        $host_url_root = '';

        $host_url_path = '';

        if (isset($host_url['host'])) {
            $host_url_root = $host_url['host'];
        }

        if (isset($host_url['path'])) {
            $host_url_path = Str::of($host_url['path'])->dirname();
        }

        $links = Str::of($html_original_content)->matchAll('/src="(.*?)"/');

        $links = $links->filter(fn ($link) => strpos($link, ".js"))
            ->map(function ($link) use ($host_url_root, $host_url_path) {
                if (strpos($link, "https://") === 0) {
                    return $link;
                }

                if ($host_url_root != '') {
                    return "https://" . $host_url_root . Str::of($link);
                }
                return $host_url_path . '/' . Str::of($link)->basename();
            });


        return $links;
    }


    private function extract_css_content($identifiers, $type, $html_content): string {
        $host_url = parse_url($this->argument('url'));

        $final_css_content = '';
        $all_css_content = $this->get_all_classes_orginal_content($html_content, $host_url);

        $identifiers->each(function ($identifier) use ($all_css_content, &$final_css_content, $type) {
            $type = $type == 'class' ? '.' : '#';
            preg_match_all('/'. $type . $identifier['original'] . '{.*?}/', $all_css_content, $class_orginal, PREG_SET_ORDER, 0);
            if (count($class_orginal) > 0) {
                $final_css_content .= Str::of($class_orginal[0][0])->replace($type . $identifier['original'], $type . $identifier['alias']);
            }
        });

        return $final_css_content;
    }

    private function replace_identifier($content, $identifiers): string {
        $identifiers->each(function ($identifier) use (&$content) {
            $content = Str::of($content)->replace($identifier['original'], $identifier['alias']);
        });
        return $content;
    }

    protected function show_all_classs_and_ids($classes, $ids): void {
        if ($this->option('list-class')) {
            $this->newLine(1);
            $this->info('List of classes');
            $this->table(
                ['Original', 'Alias'],
                $classes->map(fn($class) => [$class['original'], $class['alias']])->toArray()
            );
            $this->newLine(1);
        }

        if ($this->option('list-ids')) {
            $this->newLine(1);
            $this->info('List of ids');
            $this->table(
                ['Original', 'Alias'],
                $ids->map(fn($class) => [$class['original'], $class['alias']])->toArray()
            );
            $this->newLine(1);
        }
    }

    protected function generate_new_short_names($list): Collection {

        $new_identifier_list = collect();
        $new_identifiers = collect();
        $list->each(function ($item, $key) use ($new_identifier_list, &$new_identifiers) {
            $candidate = $this->generate_short_name($item);
            if ($new_identifier_list->contains($candidate)) {
                $candidate = $candidate . $key;
                $new_identifier_list->push($candidate);
            } else {
                $new_identifier_list->push($candidate);
            }
            $new_identifiers->push([
                'original' => $item,
                'alias' => $candidate,
                'pow' => strlen($item)
            ]);
        });

        $new_identifiers = $new_identifiers->sortBy([['pow', 'desc']])
            ->values()
            ->all();

        return collect($new_identifiers);
    }

    private function get_all_identifier($html, $identifier): Collection {
        $list = Str::of($html)->matchAll('/' .$identifier . '="([^"]*)"/');
        $list = $list->map(fn ($item) => explode(" ", $item))
            ->flatten()
            ->filter(fn ($class) => $class != "")
            ->unique()
            ->values()
            ->all();
        return collect($list);
    }

    protected function check_is_live_url($url): bool {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_NOBODY, true);

        $result = curl_exec($curl);

        if ($result) {
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            return $statusCode == 200;
        }

        return false;
    }

    protected function check_is_valid_url($url): bool {
        $regex = "((https?|ftp)\:\/\/)?";
        $regex .= "([a-z0-9+!*(),;?&=\$_.-]+(\:[a-z0-9+!*(),;?&=\$_.-]+)?@)?";
        $regex .= "([a-z0-9-.]*)\.([a-z]{2,3})";
        $regex .= "(\:[0-9]{2,5})?";
        $regex .= "(\/([a-z0-9+\$_-]\.?)+)*\/?";
        $regex .= "(\?[a-z+&\$_.-][a-z0-9;:@&%=+\/\$_.-]*)?";
        $regex .= "(#[a-z_.-][a-z0-9+\$_.-]*)?";

        return preg_match("/^$regex$/i", $url);
    }

    protected function set_new_css_file($html): string {

        $links = Str::of($html)->matchAll('/href="(.*?)"/')
            ->filter(fn ($link) => strpos($link, ".css"))
            ->values()
            ->all();

        collect($links)->each(function ($link, $index) use (&$html) {
            if ($index == 0) {
                $html = Str::of($html)->replace($link, './index.css');
            } else {
                $html = Str::of($html)->replace($link, '');
            }
        });
        $html = Str::of($html)->replace('<link rel="stylesheet" href="">', '');
        return $html;
    }


    protected function get_all_classes_orginal_content($html_original_content, $host_url):string {
        $host_url_root = '';

        $host_url_path = '';

        $all_css_content = '';

        if (isset($host_url['host'])) {
            $host_url_root = $host_url['host'];
        }

        if (isset($host_url['path'])) {
            $host_url_path = Str::of($host_url['path'])->dirname();
        }

        $links_css = Str::of($html_original_content)->matchAll('/href="(.*?)"/');

        $links_css = $links_css->filter(fn ($link) => strpos($link, ".css"))
            ->map(function ($link) use ($host_url_root, $host_url_path) {
                if (strpos($link, "https://") === 0) {
                    return $link;
                }

                if ($host_url_root != '') {
                    return "https://" . $host_url_root . Str::of($link);
                }
                return $host_url_path . '/' . Str::of($link);
            });

        $links_css->each(function ($link) use (&$all_css_content) {
            $content = '';
            if (!$this->option('local')) {
                if ($this->check_is_live_url($link)) {
                    try {
                        $content = file_get_contents($link);
                    } catch (\Exception $e) {
                        $curl = curl_init();
                        curl_setopt($curl, CURLOPT_URL, $link);
                        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($curl, CURLOPT_HEADER, false);
                        $content = curl_exec($curl);
                        curl_close($curl);
                    }

                     $all_css_content .= $this->minimize_css($content);
                }
                else {
                    $this->error('Url ' . $link . ' is not found');
                }
            } else {
                $all_css_content .= $this->minimize_css(file_get_contents($link));
            }

        });

        return $all_css_content;
    }

    protected function minimize_css($css) {
        $css = preg_replace('/\/\*((?!\*\/).)*\*\//', '', $css);
        $css = preg_replace('/\s{2,}/', ' ', $css);
        $css = preg_replace('/\s*([:;{}])\s*/', '$1', $css);
        $css = preg_replace('/;}/', '}', $css);
        return $css;
    }

    protected function generate_short_name($class_name): string {
        $words = Str::of($class_name)->explode('-');
        // Check if the class name struc is like 'class_name'
        if ($words->count() < 2) {
            $words = Str::of($class_name)->explode('_');
        }
        // Generate short name
        if ($words->count() >= 2) {
            return $words->reduce(fn ($carry, $item) => $carry . Str::of($item)->substr(0, 1));
        }
        return Str::of($class_name)->substr(0, 2);
    }

    protected function replace_xpaths(): void {
        $html_result = "./output/index.html";

        $xpaths = collect();
        $change_types = collect();
        $outputs = collect();
        $this->ask_special_tags_to_replace($xpaths,$change_types, $outputs, $html_result);
    }

    private function ask_special_tags_to_replace(Collection $xpaths,  Collection $change_types , Collection $outputs ,$html_result): void {
        $ask = $this->ask('Can yuo add special tag xpaths to replaced inside html ?  (yes/no)', 'no');
        if($ask == 'y' || $ask == 'Y' || $ask == 'yes' || $ask == 'Yes'){
            $xpath = $this->ask('Write a xpath of the special case');
            $change_type = $this->ask('Write a change type of the special case (text/html)' , 'html');
            $output = $this->ask('Write the output for this special case');
            $ask = $this->ask_add_more_xpath($xpath,$change_type, $output, $xpaths,$change_types, $outputs);
            while ($ask == 'y' || $ask == 'Y' || $ask == 'yes' || $ask == 'Yes') {
                $xpath = $this->ask('Write a xpath of the special case');
                $change_type = $this->ask('Write a change type of the special case (text/html)' , 'html');
                $output = $this->ask('Write the output for this special case');
                $ask = $this->ask_add_more_xpath($xpath,$change_type, $output, $xpaths,$change_types, $outputs);
            }
            $html_result = $this->replace_special_tags($xpaths, $change_types,$outputs, $html_result);
            file_put_contents('./output/index.html', $html_result);
        }

    }

    private function ask_add_more_xpath( $xpath, $change_type, $output, Collection $xpaths, Collection $change_types ,Collection $outputs):string {
        $xpaths->push($xpath);
        $change_types->push($change_type);
        $outputs->push($output);
        $ask = $this->ask('Can yuo add more xpaths? (yes/no)', 'no');
        return $ask;
    }

    private function replace_special_tags(Collection $xpaths, Collection $change_types , Collection $outputs, $html_result):string {
        $html_result = file_get_contents($html_result);
        $crawler = new Crawler($html_result);
        $xpaths->each(function ($xpath, $key) use ($outputs,$change_types, &$html_result, $crawler) {
            if (strpos($xpath, 'id') !== false) {
                $crawler ->filterXPath($xpath)->each(function ($node, $i) use (&$html_result, $change_types, $outputs, $key) {
                    if($change_types->get($key) == 'text'){
                        if($change_types->get($key) == 'text' && $node->nodeName() == 'p' || $node->nodeName() == 'a'  || strpos($node->nodeName(), 'h') !== false) {
                            dump('is text',$change_types->get($key));
                            $result = trim($node->innerText());
                            $html_result = Str::of($html_result)->replace($result, $outputs->get($key));
                        }
                    }else {
                        $result = $node->outerHtml();
                        $html_result = Str::of($html_result)->replace($result, $outputs->get($key));
                    }
                });
            } else {
                $crawler ->filterXPath('/'.$xpath)->each(function ($node, $i) use (&$html_result, $change_types, $outputs, $key) {
                    if($change_types->get($key) == 'text'){
                        if($change_types->get($key) == 'text' && $node->nodeName() == 'p' || $node->nodeName() == 'a'  || strpos($node->nodeName(), 'h') !== false) {
                            dump('is text',$change_types->get($key));
                            $result = trim($node->innerText());
                            $html_result = Str::of($html_result)->replace($result, $outputs->get($key));
                        }
                    }else{
                        dump('is html',$change_types->get($key));
                        $result = $node->outerHtml();
                        dump($result);
                        $html_result = Str::of($html_result)->replace($result, $outputs->get($key));
                    }
                });
            }

        });
        $this->info('Replace xPaths...');
        return $html_result;
    }

    protected function generate_new_class_short_names($classes): Collection {

        $new_class_list = collect([]);
        $new_classes = collect([]);
        $classes->each(function ($class, $key) use ($new_class_list, &$new_classes) {
            $candidate = $this->generate_short_name($class);
            if ($new_class_list->contains($candidate)) {
                $candidate = $candidate . $key;
                $new_class_list->push($candidate);
            } else {
                $new_class_list->push($candidate);
            }
            $new_classes->push([
                'original' => $class,
                'alias' => $candidate,
                'pow' => strlen($class)
            ]);
        });

        $new_classes =  $new_classes->sortBy([['pow', 'desc']])
            ->values()
            ->all();

        return collect($new_classes);
    }

}
