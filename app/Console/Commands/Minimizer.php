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

         // Generate classes and ids from the html content
        $classes = $this->generate_new_short_names($classes);
        $ids = $this->generate_new_short_names($ids);

        $this->show_all_classs_and_ids($classes, $ids);

        // Replace new identifiers in the html content
        $html_result = $this->replace_identifier($html_original_content, $classes);
        $html_result = $this->replace_identifier($html_result, $ids);

        // Extract css with new identifiers on the css files
        $css_result = $this->extract_css_content($classes, 'class', $html_original_content);
        $css_result .= $this->extract_css_content($ids, 'id', $html_original_content);

        $html_result = $this->set_new_css_file($html_result);

        // Output the result files css, html and js
        file_put_contents('./output/index.css', $css_result);
        file_put_contents('./output/index.html', $html_result);

        $html_result ="./output/index.html";

        $xpaths = collect();
        $outputs = collect();
        $this->ask_special_tags_to_replace($xpaths, $outputs);
        $html_result = $this->replace_special_tags($xpaths, $outputs, $html_result);
        $this->replace_identifier_in_js_files($html_original_content, $ids);

        $this->info('Process is Done!');

        /*$crawler = new Crawler($html_result);
        $result = $crawler ->filterXPath('//body/div[1]/div/div/div/div/div')->each(function ($node, $i) {

            $node ->html() = Str::of($node->html())->replace($node->html(), "<p>HERE</p>");
            dd($node->html());
            //$node->html() = '<p>HERE</p>';
            $node->saveHTML();
            return $node->html();
        });
        dd(trim($result[0]));
        $result = 'here is the result';
        $result->save('./output/index.html');
        dd($crawler ->outerHtml());
        dd($html_result);
        ////*[@id="app"]*/


        return 0;
    }


    private function replace_identifier_in_js_files($html_original_content, $ids) {
       $host_url = parse_url($this->argument('url'));
       $links = $this->get_js_orginal_content($html_original_content, $host_url);
       $links->each(function($link) use ($ids) {
           $link_content = file_get_contents($link);
           $link_content = $this->replace_identifier($link_content, $ids);
           file_put_contents('./output/'. Str::of($link)->basename(), $link_content);
       });
    }


    protected function get_js_orginal_content($html_original_content, $host_url) {
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


    protected function get_all_classes_orginal_content($html_original_content, $host_url) {
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
            if (!$this->option('local')) {
                if ($this->check_is_live_url($link)) {
                     $all_css_content .= $this->minimize_css(file_get_contents($link));
                }
                else {
                    $this->error('File url' . $link . ' is not found');
                }
            } else {
                $all_css_content .= $this->minimize_css(file_get_contents($link));
            }

        });

        return $all_css_content;
    }

    protected function minimize_css($css)
    {
        $css = preg_replace('/\/\*((?!\*\/).)*\*\//', '', $css);
        $css = preg_replace('/\s{2,}/', ' ', $css);
        $css = preg_replace('/\s*([:;{}])\s*/', '$1', $css);
        $css = preg_replace('/;}/', '}', $css);
        return $css;
    }


    private function get_all_classes($html): Collection
    {

        $classes = Str::of($html)->matchAll('/class="([^"]*)"/');

        $classes = $classes->map(fn ($item) => explode(" ", $item))
            ->flatten()
            ->filter(fn ($class) => $class != "")
            ->unique()
            ->values()
            ->all();

        return collect($classes);
    }

    private function replace_classes($html, $classes): string
    {

        $classes->each(function ($class) use (&$html) {
            $html = Str::of($html)->replace($class['original'], $class['alias']);
        });

        return $html;
    }

    protected function generate_new_class_short_names($classes): Collection
    {

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

    protected function generate_short_name($class_name): string
    {
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

    private function ask_special_tags_to_replace(Collection $xpaths, Collection $outputs){
        $ask = $this->ask('Can yuo add special tag xpaths to replaced inside html ?', 'no');
        if($ask == 'y' || $ask == 'Y' || $ask == 'yes' || $ask == 'Yes'){
            $xpath = $this->ask('write a xpath of the special case');
            // /html/body/me/me/me/me/div/div[2]/se[1]/h2
            // /html/body/div/div[1]/div[2]/ul/li[2]/svg
            $output = $this->ask('write the output for this special case');
            /*<svg class="svg-inline--fa fa-envelope fa-w-16" aria-hidden="true" data-prefix="fas" data-icon="envelope" role="img" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" data-fa-i2svg=""><path fill="currentColor" d="M502.3 190.8c3.9-3.1 9.7-.2 9.7 4.7V400c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V195.6c0-5 5.7-7.8 9.7-4.7 22.4 17.4 52.1 39.5 154.1 113.6 21.1 15.4 56.7 47.8 92.2 47.6 35.7.3 72-32.8 92.3-47.6 102-74.1 131.6-96.3 154-113.7zM256 320c23.2.4 56.6-29.2 73.4-41.4 132.7-96.3 142.8-104.7 173.4-128.7 5.8-4.5 9.2-11.5 9.2-18.9v-19c0-26.5-21.5-48-48-48H48C21.5 64 0 85.5 0 112v19c0 7.4 3.4 14.3 9.2 18.9 30.6 23.9 40.7 32.4 173.4 128.7 16.8 12.2 50.2 41.8 73.4 41.4z"></path></svg>*/


            $ask = $this->ask_add_more_xpath($xpath, $output, $xpaths, $outputs);
            while ($ask == 'y' || $ask == 'Y' || $ask == 'yes' || $ask == 'Yes') {
                $xpath = $this->ask('write a xpath of the special case'); // /html/body/div/div[1]/div[2]/ul/li[1]/svg
                $output = $this->ask('write the output for this special case');
                $ask = $this->ask_add_more_xpath($xpath, $output, $xpaths, $outputs);
            }
        }
    }

    private function ask_add_more_xpath( $xpath,  $output, Collection $xpaths, Collection $outputs){
        $xpaths->push($xpath);
        $outputs->push($output);
        $ask = $this->ask('Can yuo add more xpaths?', 'no');
        return $ask;
    }

    private function replace_special_tags(Collection $xpaths, Collection $outputs, $html_result){
        $html_result = file_get_contents($html_result);
        $xpaths->each(function ($xpath, $key) use ($outputs, &$html_result) {
            dump($xpath, $outputs->get($key));
            $html_result = Str::of($html_result)->replace($xpath, $outputs->get($key));
        });
        return $html_result;
    }

}
