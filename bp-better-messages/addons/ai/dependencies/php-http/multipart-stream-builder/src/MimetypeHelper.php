<?php
/**
 * @license MIT
 *
 * Modified by __root__ on 08-April-2024 using {@see https://github.com/BrianHenryIE/strauss}.
 */

namespace BetterMessages\Http\Message\MultipartStream;

/**
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
interface MimetypeHelper
{
    /**
     * Determines the mimetype of a file by looking at its extension.
     *
     * @param string $filename
     *
     * @return string|null
     */
    public function getMimetypeFromFilename($filename);

    /**
     * Maps a file extensions to a mimetype.
     *
     * @param string $extension The file extension
     *
     * @return string|null
     */
    public function getMimetypeFromExtension($extension);
}
