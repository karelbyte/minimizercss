<?php

namespace App\Console\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class Minimizer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:minimizercss {url} {--list-class} {--list-ids}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        //  dump($this->argument('url'), $this->option('list-class'), $this->option('list-ids'));

        $host_url = parse_url($this->argument('url'));

        $html_original_content = file_get_contents($this->argument('url'));

        $classes = $this->get_all_classes($html_original_content);

        $classes = $this->generate_new_class_short_names($classes);

        $html_result = $this->replace_classes($html_original_content, $classes);

        $classes_css_content = $this->extract_classes_css_content($classes, $html_original_content, $host_url);

        $html_result = $this->set_new_css_file($html_result);

        file_put_contents('./output/index.css', $classes_css_content);

        file_put_contents('./output/index.html', $html_result);

        return 0;
    }

    private function set_new_css_file($html): string {

       $links = Str::of($html)->matchAll('/href="(.*?)"/')
              ->filter(fn($link) => strpos($link, ".css"))
              ->values()
              ->all();

       collect($links)->each(function ($link, $index) use (&$html) {
            if ($index == 0) { 
                $html = Str::of($html)->replace($link, './index.css');
            }
            else {
                $html = Str::of($html)->replace($link, '');
            }   
       });
       $html = Str::of($html)->replace('<link rel="stylesheet" href="">', '');
       return $html;
    }

    private function extract_classes_css_content($classes, $html_original_content, $host_url): string
    {
        $final_css_content = '';
        $all_css_content = $this->get_all_classes_orginal_content($html_original_content, $host_url);


        $classes->each(function ($class) use ($all_css_content, &$final_css_content) {
            preg_match_all('/.'.$class['original'].'{.*?}/', $all_css_content, $class_orginal, PREG_SET_ORDER, 0);   
            if (count($class_orginal) > 0) {
                $final_css_content .= Str::of($class_orginal[0][0])->replace('.'.$class['original'], '.'.$class['alias']);
            }
       
       });

       return $final_css_content;
   
    }

    protected function get_all_classes_orginal_content($html_original_content, $host_url)
    {
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
                    return "https://" .$host_url_root. '/'. Str::of($link);
                }
                return $host_url_path. '/'. Str::of($link)->classBasename();
            });

        $links_css->each(function ($link) use (&$all_css_content) {
            $all_css_content .= $this->minimize_css(file_get_contents($link));
        });

        return $all_css_content;
    }


    protected function minimize_css($css){
        $css = preg_replace('/\/\*((?!\*\/).)*\*\//', '', $css);
        $css = preg_replace('/\s{2,}/', ' ', $css);
        $css = preg_replace('/\s*([:;{}])\s*/', '$1', $css);
        $css = preg_replace('/;}/', '}', $css);
        return $css;
    }

    private function get_all_classes($html): Collection
    {

        $html = file_get_contents($this->argument('url'));

        $classes = Str::of($html)->matchAll('/class="([^"]*)"/');
        return $classes->map(fn ($item) => explode(" ", $item))
            ->flatten()
            ->unique();
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

        return $new_classes->sortBy([['pow', 'desc']]);
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
}
