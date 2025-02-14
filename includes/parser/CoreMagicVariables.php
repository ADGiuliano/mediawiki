<?php
/**
 * Magic variable implementations provided by MediaWiki core
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Parser
 */
use MediaWiki\Config\ServiceOptions;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\ParserOutputFlags;
use Psr\Log\LoggerInterface;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Expansions of core magic variables, used by the parser.
 * @internal
 * @ingroup Parser
 */
class CoreMagicVariables {
	/** @var int Assume that no output will later be saved this many seconds after parsing */
	private const MAX_TTS = 900;

	/**
	 * Expand the magic variable given by $index.
	 * @internal
	 * @param Parser $parser
	 * @param string $id The name of the variable, and equivalently, the magic
	 *   word ID which was used to match the variable
	 * @param ConvertibleTimestamp $ts Timestamp to use when expanding magic variable
	 * @param NamespaceInfo $nsInfo The NamespaceInfo to use when expanding
	 * @param ServiceOptions $svcOptions Service options for the parser
	 * @param LoggerInterface $logger
	 * @return string|null The expanded value, or null to indicate the given
	 *  index wasn't a known magic variable.
	 */
	public static function expand(
		// Fundamental options
		Parser $parser,
		string $id,
		// Context passed over from the parser
		ConvertibleTimestamp $ts,
		NamespaceInfo $nsInfo,
		ServiceOptions $svcOptions,
		LoggerInterface $logger
	): ?string {
		$pageLang = $parser->getFunctionLang();
		$title = $parser->getTitle();

		switch ( $id ) {
			case '!':
				return '|';
			case '=':
				return '=';
			case 'currentmonth':
				return $pageLang->formatNumNoSeparators( $ts->format( 'm' ) );
			case 'currentmonth1':
				return $pageLang->formatNumNoSeparators( $ts->format( 'n' ) );
			case 'currentmonthname':
				return $pageLang->getMonthName( (int)$ts->format( 'n' ) );
			case 'currentmonthnamegen':
				return $pageLang->getMonthNameGen( (int)$ts->format( 'n' ) );
			case 'currentmonthabbrev':
				return $pageLang->getMonthAbbreviation( (int)$ts->format( 'n' ) );
			case 'currentday':
				return $pageLang->formatNumNoSeparators( $ts->format( 'j' ) );
			case 'currentday2':
				return $pageLang->formatNumNoSeparators( $ts->format( 'd' ) );
			case 'localmonth':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'm' ) );
			case 'localmonth1':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'n' ) );
			case 'localmonthname':
				return $pageLang->getMonthName( (int)self::makeTsLocal( $svcOptions, $ts )->format( 'n' ) );
			case 'localmonthnamegen':
				return $pageLang->getMonthNameGen( (int)self::makeTsLocal( $svcOptions, $ts )->format( 'n' ) );
			case 'localmonthabbrev':
				return $pageLang->getMonthAbbreviation( (int)self::makeTsLocal( $svcOptions, $ts )->format( 'n' ) );
			case 'localday':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'j' ) );
			case 'localday2':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'd' ) );
			case 'pagename':
			case 'pagenamee':
			case 'fullpagename':
			case 'fullpagenamee':
			case 'subpagename':
			case 'subpagenamee':
			case 'rootpagename':
			case 'rootpagenamee':
			case 'basepagename':
			case 'basepagenamee':
			case 'talkpagename':
			case 'talkpagenamee':
			case 'subjectpagename':
			case 'subjectpagenamee':
			case 'pageid':
			case 'namespace':
			case 'namespacee':
			case 'namespacenumber':
			case 'talkspace':
			case 'talkspacee':
			case 'subjectspace':
			case 'subjectspacee':
			case 'cascadingsources':
				# First argument of the corresponding parser function
				# (second argument of the PHP implementation) is
				# "title".

				# Note that for many of these {{FOO}} is subtly different
				# from {{FOO:{{PAGENAME}}}}, so we can't pass $title here
				# we have to explicitly use the "no arguments" form of the
				# parser function by passing `null` to indicate a missing
				# argument (which then defaults to the current page title).
				return CoreParserFunctions::$id( $parser, null );
			case 'revisionid':
				$namespace = $title->getNamespace();
				if (
					$svcOptions->get( MainConfigNames::MiserMode ) &&
					!$parser->getOptions()->getInterfaceMessage() &&
					// @TODO: disallow this variable on all namespaces
					$nsInfo->isSubject( $namespace )
				) {
					// Use a stub result instead of the actual revision ID in order to avoid
					// double parses on page save but still allow preview detection (T137900)
					if ( $parser->getRevisionId() || $parser->getOptions()->getSpeculativeRevId() ) {
						return '-';
					} else {
						self::setOutputFlag(
							$parser,
							$logger,
							ParserOutputFlags::VARY_REVISION_EXISTS,
							'{{REVISIONID}} used'
						);
						return '';
					}
				} else {
					// Inform the edit saving system that getting the canonical output after
					// revision insertion requires a parse that used that exact revision ID
					self::setOutputFlag( $parser, $logger, ParserOutputFlags::VARY_REVISION_ID, '{{REVISIONID}} used' );
					$value = $parser->getRevisionId();
					if ( $value === 0 ) {
						$rev = $parser->getRevisionRecordObject();
						$value = $rev ? $rev->getId() : $value;
					}
					if ( !$value ) {
						$value = $parser->getOptions()->getSpeculativeRevId();
						if ( $value ) {
							$parser->getOutput()->setSpeculativeRevIdUsed( $value );
						}
					}
					return (string)$value;
				}
			case 'revisionday':
				return strval( (int)self::getRevisionTimestampSubstring(
					$parser, $logger, 6, 2, self::MAX_TTS, $id
				) );
			case 'revisionday2':
				return self::getRevisionTimestampSubstring(
					$parser, $logger, 6, 2, self::MAX_TTS, $id
				);
			case 'revisionmonth':
				return self::getRevisionTimestampSubstring(
					$parser, $logger, 4, 2, self::MAX_TTS, $id
				);
			case 'revisionmonth1':
				return strval( (int)self::getRevisionTimestampSubstring(
					$parser, $logger, 4, 2, self::MAX_TTS, $id
				) );
			case 'revisionyear':
				return self::getRevisionTimestampSubstring(
					$parser, $logger, 0, 4, self::MAX_TTS, $id
				);
			case 'revisiontimestamp':
				return self::getRevisionTimestampSubstring(
					$parser, $logger, 0, 14, self::MAX_TTS, $id
				);
			case 'revisionuser':
				// Inform the edit saving system that getting the canonical output after
				// revision insertion requires a parse that used the actual user ID
				self::setOutputFlag( $parser, $logger, ParserOutputFlags::VARY_USER, '{{REVISIONUSER}} used' );
				// Note that getRevisionUser() can return null; we need to
				// be sure to cast this to (an empty) string, since 'null'
				// means "magic variable not handled here".
				return (string)$parser->getRevisionUser();
			case 'revisionsize':
				return (string)$parser->getRevisionSize();
			case 'currentdayname':
				return $pageLang->getWeekdayName( (int)$ts->format( 'w' ) + 1 );
			case 'currentyear':
				return $pageLang->formatNumNoSeparators( $ts->format( 'Y' ) );
			case 'currenttime':
				return $pageLang->time( $ts->getTimestamp( TS_MW ), false, false );
			case 'currenthour':
				return $pageLang->formatNumNoSeparators( $ts->format( 'H' ) );
			case 'currentweek':
				// @bug T6594 PHP5 has it zero padded, PHP4 does not, cast to
				// int to remove the padding
				return $pageLang->formatNum( (int)$ts->format( 'W' ) );
			case 'currentdow':
				return $pageLang->formatNum( $ts->format( 'w' ) );
			case 'localdayname':
				return $pageLang->getWeekdayName(
					(int)self::makeTsLocal( $svcOptions, $ts )->format( 'w' ) + 1
				);
			case 'localyear':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'Y' ) );
			case 'localtime':
				return $pageLang->time(
					self::makeTsLocal( $svcOptions, $ts )->format( 'YmdHis' ),
					false,
					false
				);
			case 'localhour':
				return $pageLang->formatNumNoSeparators( self::makeTsLocal( $svcOptions, $ts )->format( 'H' ) );
			case 'localweek':
				// @bug T6594 PHP5 has it zero padded, PHP4 does not, cast to
				// int to remove the padding
				return $pageLang->formatNum( (int)self::makeTsLocal( $svcOptions, $ts )->format( 'W' ) );
			case 'localdow':
				return $pageLang->formatNum( self::makeTsLocal( $svcOptions, $ts )->format( 'w' ) );
			case 'numberofarticles':
			case 'numberoffiles':
			case 'numberofusers':
			case 'numberofactiveusers':
			case 'numberofpages':
			case 'numberofadmins':
			case 'numberofedits':
				# second argument is 'raw'; magic variables are "not raw"
				return CoreParserFunctions::$id( $parser, null );
			case 'currenttimestamp':
				return $ts->getTimestamp( TS_MW );
			case 'localtimestamp':
				return self::makeTsLocal( $svcOptions, $ts )->format( 'YmdHis' );
			case 'currentversion':
				return SpecialVersion::getVersion();
			case 'articlepath':
				return (string)$svcOptions->get( MainConfigNames::ArticlePath );
			case 'sitename':
				return (string)$svcOptions->get( MainConfigNames::Sitename );
			case 'server':
				return (string)$svcOptions->get( MainConfigNames::Server );
			case 'servername':
				return (string)$svcOptions->get( MainConfigNames::ServerName );
			case 'scriptpath':
				return (string)$svcOptions->get( MainConfigNames::ScriptPath );
			case 'stylepath':
				return (string)$svcOptions->get( MainConfigNames::StylePath );
			case 'directionmark':
				return $pageLang->getDirMark();
			case 'contentlanguage':
				return $parser->getContentLanguage()->getCode();
			case 'pagelanguage':
				return $pageLang->getCode();
			default:
				// This is not one of the core magic variables
				return null;
		}
	}

	/**
	 * Helper to convert a timestamp instance to local time
	 * @see MWTimestamp::getLocalInstance()
	 * @param ServiceOptions $svcOptions Service options for the parser
	 * @param ConvertibleTimestamp $ts Timestamp to convert
	 * @return ConvertibleTimestamp
	 */
	private static function makeTsLocal( $svcOptions, $ts ) {
		$localtimezone = $svcOptions->get( MainConfigNames::Localtimezone );
		$ts->setTimezone( $localtimezone );
		return $ts;
	}

	/**
	 * @param Parser $parser
	 * @param LoggerInterface $logger
	 * @param int $start
	 * @param int $len
	 * @param int $mtts Max time-till-save; sets vary-revision-timestamp if result changes by then
	 * @param string $variable Parser variable name
	 * @return string
	 */
	private static function getRevisionTimestampSubstring(
		Parser $parser,
		LoggerInterface $logger,
		int $start,
		int $len,
		int $mtts,
		string $variable
	): string {
		// Get the timezone-adjusted timestamp to be used for this revision
		$resNow = substr( $parser->getRevisionTimestamp(), $start, $len );
		// Possibly set vary-revision if there is not yet an associated revision
		if ( !$parser->getRevisionRecordObject() ) {
			// Get the timezone-adjusted timestamp $mtts seconds in the future.
			// This future is relative to the current time and not that of the
			// parser options. The rendered timestamp can be compared to that
			// of the timestamp specified by the parser options.
			$resThen = substr(
				$parser->getContentLanguage()->userAdjust( wfTimestamp( TS_MW, time() + $mtts ), '' ),
				$start,
				$len
			);

			if ( $resNow !== $resThen ) {
				// Inform the edit saving system that getting the canonical output after
				// revision insertion requires a parse that used an actual revision timestamp
				self::setOutputFlag( $parser, $logger, ParserOutputFlags::VARY_REVISION_TIMESTAMP, "$variable used" );
			}
		}

		return $resNow;
	}

	/**
	 * Helper method borrowed from Parser.php: sets the flag on the output
	 * but also does some debug logging.
	 * @param Parser $parser
	 * @param LoggerInterface $logger
	 * @param string $flag
	 * @param string $reason
	 */
	private static function setOutputFlag(
		Parser $parser,
		LoggerInterface $logger,
		string $flag,
		string $reason
	): void {
		$parser->getOutput()->setOutputFlag( $flag );
		$name = $parser->getTitle()->getPrefixedText();
		// This code was moved from Parser::setOutputFlag and used __METHOD__
		// originally; we've hard-coded that output here so that our refactor
		// doesn't change the messages in the logs.
		$logger->debug( "Parser::setOutputFlag: set $flag flag on '$name'; $reason" );
	}
}
