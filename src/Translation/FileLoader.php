<?php namespace October\Rain\Translation;

use Illuminate\Translation\FileLoader as FileLoaderBase;
use Illuminate\Filesystem\Filesystem;

class FileLoader extends FileLoaderBase
{
    protected $path;

    public function __construct(Filesystem $files, $path)
    {
        parent::__construct($files, $path);
        $this->path = $path;
    }

    /**
     * Load a namespaced translation group.
     *
     * @param  string  $locale
     * @param  string  $group
     * @param  string  $namespace
     * @return array
     */
    protected function loadNamespaced($locale, $group, $namespace)
    {
        if (isset($this->hints[$namespace])) {
            $namespacePath = $this->hints[$namespace];
            $filePath = $namespacePath.'/'.$locale.'/'.$group.'.php';

            if ($this->files->exists($filePath)) {
                $lines = $this->files->getRequire($filePath);
                return is_array($lines) ? $this->loadNamespaceOverrides($lines, $locale, $group, $namespace) : [];
            }
        }

        return [];
    }

    /**
     * Load a local namespaced translation group for overrides.
     *
     * @param  array  $lines
     * @param  string  $locale
     * @param  string  $group
     * @param  string  $namespace
     * @return array
     */
    protected function loadNamespaceOverrides(array $lines, $locale, $group, $namespace)
    {
        $namespace = str_replace('.', '/', $namespace);
        $file = "{$this->path}/{$locale}/{$namespace}/{$group}.php";

        if ($this->files->exists($file)) {
            return array_replace_recursive($lines, $this->files->getRequire($file));
        }

        return $lines;
    }
}
