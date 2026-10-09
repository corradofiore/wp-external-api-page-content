<?php
/**
 * Small, dependency-free Markdown renderer for legal/policy content.
 *
 * Supported blocks: headings, paragraphs, blockquotes, horizontal rules,
 * fenced code blocks, unordered lists, ordered lists.
 * Supported inline syntax: links, code, bold, emphasis, strikethrough.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class HiveKit_EAPC_Markdown {
    public static function render( $markdown ) {
        $markdown = str_replace( array( "\r\n", "\r" ), "\n", (string) $markdown );
        $lines    = explode( "\n", $markdown );
        $html     = array();
        $count    = count( $lines );
        $i        = 0;

        while ( $i < $count ) {
            $line = $lines[ $i ];

            if ( '' === trim( $line ) ) {
                $i++;
                continue;
            }

            if ( preg_match( '/^\s*```\s*([A-Za-z0-9_+.-]*)\s*$/', $line, $m ) ) {
                $language = isset( $m[1] ) ? $m[1] : '';
                $i++;
                $buffer = array();
                while ( $i < $count && ! preg_match( '/^\s*```\s*$/', $lines[ $i ] ) ) {
                    $buffer[] = $lines[ $i ];
                    $i++;
                }
                if ( $i < $count ) {
                    $i++;
                }

                $class = '' !== $language ? ' class="language-' . esc_attr( $language ) . '"' : '';
                $html[] = '<pre><code' . $class . '>' . esc_html( implode( "\n", $buffer ) ) . '</code></pre>';
                continue;
            }

            if ( preg_match( '/^\s*(#{1,6})\s+(.+?)\s*#*\s*$/', $line, $m ) ) {
                $level  = strlen( $m[1] );
                $html[] = '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . '>';
                $i++;
                continue;
            }

            if ( preg_match( '/^\s{0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})\s*$/', $line ) ) {
                $html[] = '<hr>';
                $i++;
                continue;
            }

            if ( preg_match( '/^\s*>\s?(.*)$/', $line ) ) {
                $quote = array();
                while ( $i < $count && preg_match( '/^\s*>\s?(.*)$/', $lines[ $i ], $m ) ) {
                    $quote[] = self::inline( $m[1] );
                    $i++;
                }
                $html[] = '<blockquote><p>' . implode( '<br>', $quote ) . '</p></blockquote>';
                continue;
            }

            if ( self::is_unordered_list_item( $line ) ) {
                $items = array();
                while ( $i < $count && self::is_unordered_list_item( $lines[ $i ], $item ) ) {
                    $items[] = '<li>' . self::inline( $item ) . '</li>';
                    $i++;
                }
                $html[] = '<ul>' . implode( '', $items ) . '</ul>';
                continue;
            }

            if ( self::is_ordered_list_item( $line ) ) {
                $items = array();
                while ( $i < $count && self::is_ordered_list_item( $lines[ $i ], $item ) ) {
                    $items[] = '<li>' . self::inline( $item ) . '</li>';
                    $i++;
                }
                $html[] = '<ol>' . implode( '', $items ) . '</ol>';
                continue;
            }

            $paragraph = array();
            while ( $i < $count && '' !== trim( $lines[ $i ] ) && ! self::starts_block( $lines[ $i ], ! empty( $paragraph ) ) ) {
                $paragraph[] = trim( $lines[ $i ] );
                $i++;
            }

            if ( empty( $paragraph ) ) {
                $paragraph[] = trim( $line );
                $i++;
            }

            $html[] = '<p>' . self::inline( implode( ' ', $paragraph ) ) . '</p>';
        }

        return implode( "\n", $html );
    }

    private static function starts_block( $line, $paragraph_started ) {
        if ( ! $paragraph_started ) {
            return false;
        }

        return (bool) (
            preg_match( '/^\s*```/', $line ) ||
            preg_match( '/^\s*(#{1,6})\s+/', $line ) ||
            preg_match( '/^\s*>\s?/', $line ) ||
            preg_match( '/^\s*[-+*]\s+/', $line ) ||
            preg_match( '/^\s*\d+[.)]\s+/', $line ) ||
            preg_match( '/^\s{0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})\s*$/', $line )
        );
    }

    private static function is_unordered_list_item( $line, &$item = null ) {
        if ( preg_match( '/^\s*[-+*]\s+(.+)$/', $line, $m ) ) {
            $item = $m[1];
            return true;
        }
        return false;
    }

    private static function is_ordered_list_item( $line, &$item = null ) {
        if ( preg_match( '/^\s*\d+[.)]\s+(.+)$/', $line, $m ) ) {
            $item = $m[1];
            return true;
        }
        return false;
    }

    private static function inline( $text ) {
        $tokens = array();

        $text = preg_replace_callback(
            '/`([^`]+)`/',
            function ( $m ) use ( &$tokens ) {
                $token = 'HIVEKIT_EAPCTOKEN' . count( $tokens ) . 'PLACEHOLDER';
                $tokens[ $token ] = '<code>' . esc_html( $m[1] ) . '</code>';
                return $token;
            },
            (string) $text
        );

        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/i',
            function ( $m ) use ( &$tokens ) {
                $token = 'HIVEKIT_EAPCTOKEN' . count( $tokens ) . 'PLACEHOLDER';
                $href  = esc_url( $m[2], array( 'http', 'https', 'mailto' ) );
                if ( '' === $href ) {
                    $tokens[ $token ] = esc_html( $m[1] );
                } else {
                    $tokens[ $token ] = '<a href="' . esc_attr( $href ) . '">' . esc_html( $m[1] ) . '</a>';
                }
                return $token;
            },
            $text
        );

        $text = esc_html( $text );

        $text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
        $text = preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );
        $text = preg_replace( '/~~(.+?)~~/s', '<del>$1</del>', $text );
        $text = preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/s', '<em>$1</em>', $text );
        $text = preg_replace( '/(?<!_)_([^_]+)_(?!_)/s', '<em>$1</em>', $text );

        if ( ! empty( $tokens ) ) {
            $text = strtr( $text, $tokens );
        }

        return $text;
    }
}
