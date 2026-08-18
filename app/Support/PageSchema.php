<?php

namespace App\Support;

use App\Models\Package;
use App\Models\Repository;

/**
 * The JSON-LD a public page carries.
 *
 * Structured data is how a search engine learns what a page *is* rather than
 * what words it contains — and a package page is exactly the case where the
 * prose is somebody's README and the facts are in this database. Naming the
 * version, the license, the source repository and the publisher explicitly is
 * what earns a rich result rather than a blue link.
 *
 * Built here rather than in the Blade template so the documents have one
 * shape, tested in one place, instead of being assembled inside a view where
 * a missing key is a silent omission.
 */
class PageSchema
{
    /**
     * schema.org/SoftwareSourceCode for a package page.
     *
     * SoftwareSourceCode rather than SoftwareApplication: a Composer package
     * is source somebody builds against, not an application a reader
     * installs and runs — and the vocabulary is what tells the two apart.
     *
     * @return array<string, mixed>
     */
    public static function package(Package $package): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareSourceCode',
            'name' => $package->name,
            'description' => $package->pageSummary(),
            'url' => $package->pageUrl(),
            'programmingLanguage' => 'PHP',
            'publisher' => [
                '@type' => 'Organization',
                'name' => (string) config('app.name'),
                'url' => $package->composerRepository->pageRootUrl(),
            ],
        ];

        if ($package->latest_version !== null) {
            $schema['softwareVersion'] = $package->latest_version;
        }

        if (filled($package->repository)) {
            $schema['codeRepository'] = (string) $package->repository;
        }

        // The license of the release the page describes, which is the one a
        // reader is deciding about — already reduced to a single SPDX
        // expression on the version row.
        $license = $package->versions()
            ->where('version', $package->latest_version)
            ->value('license');

        if (filled($license)) {
            $schema['license'] = (string) $license;
        }

        if ($image = $package->pageImageUrl()) {
            $schema['image'] = $image;
        }

        return $schema;
    }

    /**
     * schema.org/CollectionPage for a repository's landing page, with the
     * packages it lists as its items — which is what makes the list itself
     * eligible to be understood as a list rather than as a run of links.
     *
     * @param  iterable<int, Package>  $packages
     * @return array<string, mixed>
     */
    public static function repository(Repository $repository, iterable $packages): array
    {
        $items = [];

        foreach ($packages as $position => $package) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $package->name,
                'url' => $package->pageUrl(),
            ];
        }

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $repository->name,
            'description' => $repository->pageSummary(),
            'url' => $repository->pageUrl(),
        ];

        if ($items !== []) {
            $schema['mainEntity'] = [
                '@type' => 'ItemList',
                'numberOfItems' => count($items),
                'itemListElement' => $items,
            ];
        }

        if ($image = $repository->pageImageUrl()) {
            $schema['image'] = $image;
        }

        return $schema;
    }
}
