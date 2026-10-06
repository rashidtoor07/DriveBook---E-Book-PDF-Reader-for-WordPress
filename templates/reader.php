<?php
/**
 * Reader markup. Variables come from VWL_Ebook_Renderer::render() via $view.
 *
 * Themes can override this file by copying it to:
 * your-theme/wp-flip-book/reader.php
 *
 * @package VWL_Ebook_Reader
 *
 * @var array $view
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$vwl_override = locate_template( array( 'wp-flip-book/reader.php' ) );
if ( $vwl_override && realpath( $vwl_override ) !== realpath( __FILE__ ) ) {
	include $vwl_override;
	return;
}

$vwl_f     = $view['f'];
$vwl_id    = $view['reader_id'];
$vwl_title = $view['title'];
$vwl_theme = 'dark' === $view['theme'] ? 'dark' : 'light';
?>
<div
	id="<?php echo esc_attr( $vwl_id ); ?>"
	class="<?php echo esc_attr( implode( ' ', $view['classes'] ) ); ?>"
	style="<?php echo esc_attr( $view['style'] ); ?>"
	data-theme="<?php echo esc_attr( $vwl_theme ); ?>"
	data-vwl-ebook="<?php echo esc_attr( wp_json_encode( $view['config'] ) ); ?>"
	role="region"
	aria-label="<?php /* translators: %s: book title */ echo esc_attr( sprintf( __( 'E-book reader: %s', 'wp-flip-book' ), $vwl_title ) ); ?>"
>
	<div class="vwl-ebook__progress" aria-hidden="true"><span class="vwl-ebook__progress-fill" data-ref="progressFill"></span></div>

	<?php if ( $vwl_f['toolbar'] ) : ?>
	<div class="vwl-ebook__toolbar" role="toolbar" aria-label="<?php esc_attr_e( 'Reader controls', 'wp-flip-book' ); ?>">
		<div class="vwl-ebook__tb vwl-ebook__tb--start">
			<?php if ( $vwl_f['toc'] ) : ?>
			<button type="button" class="vwl-ebook__btn" data-action="toc" data-ref="tocBtn" aria-label="<?php esc_attr_e( 'Table of contents', 'wp-flip-book' ); ?>" aria-expanded="false" title="<?php esc_attr_e( 'Table of contents', 'wp-flip-book' ); ?>" hidden><?php VWL_Ebook_Icons::render( 'toc' ); ?></button>
			<?php endif; ?>
			<span class="vwl-ebook__title" title="<?php echo esc_attr( $vwl_title ); ?>"><?php echo esc_html( $vwl_title ); ?></span>
		</div>

		<?php if ( $vwl_f['nav'] ) : ?>
		<div class="vwl-ebook__tb vwl-ebook__tb--center vwl-ebook__wide-only">
			<button type="button" class="vwl-ebook__btn" data-action="prev" aria-label="<?php esc_attr_e( 'Previous page', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Previous page', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'prev' ); ?></button>
			<label class="vwl-ebook__pagebox">
				<span class="vwl-ebook__sr"><?php esc_html_e( 'Go to page', 'wp-flip-book' ); ?></span>
				<input type="text" inputmode="numeric" pattern="[0-9]*" class="vwl-ebook__page-input" data-ref="pageInput" value="1" autocomplete="off" />
				<span class="vwl-ebook__page-total">/ <span data-ref="pageTotal">–</span></span>
			</label>
			<button type="button" class="vwl-ebook__btn" data-action="next" aria-label="<?php esc_attr_e( 'Next page', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Next page', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'next' ); ?></button>
		</div>
		<?php endif; ?>

		<div class="vwl-ebook__tb vwl-ebook__tb--end">
			<?php if ( $vwl_f['zoom'] ) : ?>
			<div class="vwl-ebook__group vwl-ebook__wide-only" role="group" aria-label="<?php esc_attr_e( 'Zoom', 'wp-flip-book' ); ?>">
				<button type="button" class="vwl-ebook__btn" data-action="zoom-out" aria-label="<?php esc_attr_e( 'Zoom out', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Zoom out (-)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'zoom-out' ); ?></button>
				<button type="button" class="vwl-ebook__btn vwl-ebook__zoom-value" data-action="zoom-reset" data-ref="zoomValue" aria-label="<?php esc_attr_e( 'Reset zoom', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Reset zoom', 'wp-flip-book' ); ?>">100%</button>
				<button type="button" class="vwl-ebook__btn" data-action="zoom-in" aria-label="<?php esc_attr_e( 'Zoom in', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Zoom in (+)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'zoom-in' ); ?></button>
				<button type="button" class="vwl-ebook__btn" data-action="fit-width" aria-pressed="false" aria-label="<?php esc_attr_e( 'Fit width', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Fit width', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'fit-width' ); ?></button>
				<button type="button" class="vwl-ebook__btn" data-action="fit-page" aria-pressed="false" aria-label="<?php esc_attr_e( 'Fit page', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Fit page', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'fit-page' ); ?></button>
			</div>
			<?php endif; ?>

			<?php if ( $vwl_f['search'] ) : ?>
			<button type="button" class="vwl-ebook__btn" data-action="search" aria-label="<?php esc_attr_e( 'Search this book', 'wp-flip-book' ); ?>" aria-expanded="false" title="<?php esc_attr_e( 'Search (Ctrl+F)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'search' ); ?></button>
			<?php endif; ?>

			<?php if ( $vwl_f['darkmode'] ) : ?>
			<button type="button" class="vwl-ebook__btn vwl-ebook__wide-only" data-action="theme" aria-label="<?php esc_attr_e( 'Toggle dark mode', 'wp-flip-book' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Dark mode', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'moon' ); ?><?php VWL_Ebook_Icons::render( 'sun' ); ?></button>
			<?php endif; ?>

			<?php if ( $vwl_f['download'] ) : ?>
			<a class="vwl-ebook__btn vwl-ebook__wide-only" data-ref="downloadLink" href="<?php echo esc_url( $view['config']['downloadUrl'] ); ?>" rel="nofollow" aria-label="<?php esc_attr_e( 'Download PDF', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Download PDF', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'download' ); ?></a>
			<?php endif; ?>

			<?php if ( $vwl_f['print'] ) : ?>
			<button type="button" class="vwl-ebook__btn vwl-ebook__wide-only" data-action="print" aria-label="<?php esc_attr_e( 'Print', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Print', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'print' ); ?></button>
			<?php endif; ?>

			<?php if ( $vwl_f['fullscreen'] ) : ?>
			<button type="button" class="vwl-ebook__btn" data-action="fullscreen" aria-label="<?php esc_attr_e( 'Fullscreen', 'wp-flip-book' ); ?>" aria-pressed="false" title="<?php esc_attr_e( 'Fullscreen (F)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'fullscreen' ); ?><?php VWL_Ebook_Icons::render( 'fullscreen-off' ); ?></button>
			<?php endif; ?>

			<button type="button" class="vwl-ebook__btn vwl-ebook__narrow-only" data-action="more" data-ref="moreBtn" aria-haspopup="true" aria-expanded="false" aria-label="<?php esc_attr_e( 'More options', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'More options', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'more' ); ?></button>

			<div class="vwl-ebook__menu" data-ref="menu" role="menu" hidden>
				<?php if ( $vwl_f['zoom'] ) : ?>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="zoom-in"><?php VWL_Ebook_Icons::render( 'zoom-in' ); ?><span><?php esc_html_e( 'Zoom in', 'wp-flip-book' ); ?></span></button>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="zoom-out"><?php VWL_Ebook_Icons::render( 'zoom-out' ); ?><span><?php esc_html_e( 'Zoom out', 'wp-flip-book' ); ?></span></button>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="fit-width"><?php VWL_Ebook_Icons::render( 'fit-width' ); ?><span><?php esc_html_e( 'Fit width', 'wp-flip-book' ); ?></span></button>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="fit-page"><?php VWL_Ebook_Icons::render( 'fit-page' ); ?><span><?php esc_html_e( 'Fit page', 'wp-flip-book' ); ?></span></button>
				<?php endif; ?>
				<?php if ( $vwl_f['toc'] ) : ?>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="toc" data-ref="tocMenuItem" hidden><?php VWL_Ebook_Icons::render( 'toc' ); ?><span><?php esc_html_e( 'Table of contents', 'wp-flip-book' ); ?></span></button>
				<?php endif; ?>
				<?php if ( $vwl_f['darkmode'] ) : ?>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="theme"><?php VWL_Ebook_Icons::render( 'moon' ); ?><?php VWL_Ebook_Icons::render( 'sun' ); ?><span><?php esc_html_e( 'Dark mode', 'wp-flip-book' ); ?></span></button>
				<?php endif; ?>
				<?php if ( $vwl_f['download'] ) : ?>
				<a role="menuitem" class="vwl-ebook__menu-item" href="<?php echo esc_url( $view['config']['downloadUrl'] ); ?>" rel="nofollow"><?php VWL_Ebook_Icons::render( 'download' ); ?><span><?php esc_html_e( 'Download PDF', 'wp-flip-book' ); ?></span></a>
				<?php endif; ?>
				<?php if ( $vwl_f['print'] ) : ?>
				<button type="button" role="menuitem" class="vwl-ebook__menu-item" data-action="print"><?php VWL_Ebook_Icons::render( 'print' ); ?><span><?php esc_html_e( 'Print', 'wp-flip-book' ); ?></span></button>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( $vwl_f['search'] ) : ?>
	<div class="vwl-ebook__search" data-ref="searchBar" role="search" hidden>
		<?php VWL_Ebook_Icons::render( 'search' ); ?>
		<label class="vwl-ebook__sr" for="<?php echo esc_attr( $vwl_id ); ?>-q"><?php esc_html_e( 'Search this book', 'wp-flip-book' ); ?></label>
		<input id="<?php echo esc_attr( $vwl_id ); ?>-q" type="search" class="vwl-ebook__search-input" data-ref="searchInput" placeholder="<?php esc_attr_e( 'Search this book…', 'wp-flip-book' ); ?>" autocomplete="off" enterkeyhint="search" />
		<span class="vwl-ebook__search-status" data-ref="searchStatus" aria-live="polite"></span>
		<button type="button" class="vwl-ebook__btn" data-action="search-prev" aria-label="<?php esc_attr_e( 'Previous match', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Previous match (Shift+Enter)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'up' ); ?></button>
		<button type="button" class="vwl-ebook__btn" data-action="search-next" aria-label="<?php esc_attr_e( 'Next match', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Next match (Enter)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'down' ); ?></button>
		<button type="button" class="vwl-ebook__btn" data-action="search-close" aria-label="<?php esc_attr_e( 'Close search', 'wp-flip-book' ); ?>" title="<?php esc_attr_e( 'Close search (Esc)', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'close' ); ?></button>
	</div>
	<?php endif; ?>

	<div class="vwl-ebook__body">
		<?php if ( $vwl_f['toc'] ) : ?>
		<div class="vwl-ebook__scrim" data-action="toc-close" data-ref="scrim" hidden></div>
		<nav class="vwl-ebook__toc" data-ref="toc" aria-label="<?php esc_attr_e( 'Table of contents', 'wp-flip-book' ); ?>" hidden>
			<div class="vwl-ebook__toc-head">
				<span class="vwl-ebook__toc-title"><?php esc_html_e( 'Contents', 'wp-flip-book' ); ?></span>
				<button type="button" class="vwl-ebook__btn" data-action="toc-close" aria-label="<?php esc_attr_e( 'Close table of contents', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'close' ); ?></button>
			</div>
			<div class="vwl-ebook__toc-list" data-ref="tocList"></div>
		</nav>
		<?php endif; ?>

		<div class="vwl-ebook__stage" data-ref="stage" tabindex="0" aria-label="<?php esc_attr_e( 'Book pages. Use the arrow keys to turn pages.', 'wp-flip-book' ); ?>">
			<div class="vwl-ebook__viewport" data-ref="viewport">
				<div class="vwl-ebook__canvas-area">
					<div class="vwl-ebook__book" data-ref="book"></div>
				</div>
			</div>
			<?php if ( $vwl_f['nav'] ) : ?>
			<button type="button" class="vwl-ebook__edge vwl-ebook__edge--prev" data-action="prev" tabindex="-1" aria-hidden="true"><?php VWL_Ebook_Icons::render( 'prev' ); ?></button>
			<button type="button" class="vwl-ebook__edge vwl-ebook__edge--next" data-action="next" tabindex="-1" aria-hidden="true"><?php VWL_Ebook_Icons::render( 'next' ); ?></button>
			<?php endif; ?>
			<div class="vwl-ebook__sr" data-ref="live" aria-live="polite" aria-atomic="true"></div>
		</div>
	</div>

	<div class="vwl-ebook__footer">
		<?php if ( $vwl_f['nav'] ) : ?>
		<button type="button" class="vwl-ebook__btn" data-action="prev" aria-label="<?php esc_attr_e( 'Previous page', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'prev' ); ?></button>
		<?php endif; ?>
		<div class="vwl-ebook__status">
			<span class="vwl-ebook__status-label" data-ref="statusLabel">&nbsp;</span>
			<input type="range" class="vwl-ebook__scrubber vwl-ebook__wide-only" data-ref="scrubber" min="1" max="1" value="1" step="1" aria-label="<?php esc_attr_e( 'Jump to page', 'wp-flip-book' ); ?>" />
			<span class="vwl-ebook__status-pct vwl-ebook__wide-only" data-ref="statusPct"></span>
		</div>
		<?php if ( $vwl_f['nav'] ) : ?>
		<button type="button" class="vwl-ebook__btn" data-action="next" aria-label="<?php esc_attr_e( 'Next page', 'wp-flip-book' ); ?>"><?php VWL_Ebook_Icons::render( 'next' ); ?></button>
		<?php endif; ?>
	</div>

	<!-- Overlays -->
	<div class="vwl-ebook__overlay vwl-ebook__loader" data-ref="loader" role="status">
		<div class="vwl-ebook__loader-book" aria-hidden="true"><span></span><span></span><span></span></div>
		<p class="vwl-ebook__loader-text" data-ref="loaderText"><?php esc_html_e( 'Opening your book…', 'wp-flip-book' ); ?></p>
		<p class="vwl-ebook__loader-sub" data-ref="loaderSub"></p>
	</div>

	<div class="vwl-ebook__overlay vwl-ebook__error" data-ref="error" role="alert" hidden>
		<div class="vwl-ebook__dialog">
			<?php VWL_Ebook_Icons::render( 'book' ); ?>
			<p class="vwl-ebook__dialog-title"><?php esc_html_e( 'Unable to open this book', 'wp-flip-book' ); ?></p>
			<p class="vwl-ebook__dialog-text" data-ref="errorText"></p>
			<p class="vwl-ebook__dialog-hint" data-ref="errorHint" hidden></p>
			<div class="vwl-ebook__dialog-actions">
				<button type="button" class="vwl-ebook__cta" data-action="retry"><?php esc_html_e( 'Try again', 'wp-flip-book' ); ?></button>
				<button type="button" class="vwl-ebook__cta vwl-ebook__cta--ghost" data-action="fallback" data-ref="fallbackBtn" hidden><?php esc_html_e( 'Open with Google Drive viewer', 'wp-flip-book' ); ?></button>
			</div>
		</div>
	</div>

	<div class="vwl-ebook__overlay vwl-ebook__fallback" data-ref="fallback" hidden></div>

	<?php if ( $view['cover'] ) : ?>
	<div class="vwl-ebook__overlay vwl-ebook__cover" data-ref="cover">
		<div class="vwl-ebook__cover-inner">
			<div class="vwl-ebook__cover-art" data-ref="coverArt">
				<?php if ( ! empty( $view['config']['coverImage'] ) ) : ?>
				<img src="<?php echo esc_url( $view['config']['coverImage'] ); ?>" alt="" loading="lazy" decoding="async" />
				<?php else : ?>
				<span class="vwl-ebook__cover-placeholder"><?php echo esc_html( $vwl_title ); ?></span>
				<?php endif; ?>
			</div>
			<p class="vwl-ebook__cover-title"><?php echo esc_html( $vwl_title ); ?></p>
			<?php if ( '' !== $view['author'] ) : ?>
			<p class="vwl-ebook__cover-author"><?php echo esc_html( $view['author'] ); ?></p>
			<?php endif; ?>
			<button type="button" class="vwl-ebook__cta" data-action="start"><?php esc_html_e( 'Start reading', 'wp-flip-book' ); ?></button>
		</div>
	</div>
	<?php endif; ?>

	<div class="vwl-ebook__overlay vwl-ebook__resume" data-ref="resume" hidden>
		<div class="vwl-ebook__dialog">
			<p class="vwl-ebook__dialog-title" data-ref="resumeText"></p>
			<div class="vwl-ebook__dialog-actions">
				<button type="button" class="vwl-ebook__cta" data-action="resume-yes"><?php esc_html_e( 'Continue reading', 'wp-flip-book' ); ?></button>
				<button type="button" class="vwl-ebook__cta vwl-ebook__cta--ghost" data-action="resume-no"><?php esc_html_e( 'Start from beginning', 'wp-flip-book' ); ?></button>
			</div>
		</div>
	</div>

	<div class="vwl-ebook__overlay vwl-ebook__complete" data-ref="complete" hidden>
		<div class="vwl-ebook__dialog">
			<p class="vwl-ebook__dialog-title" data-ref="completeText"></p>
			<div class="vwl-ebook__dialog-actions">
				<button type="button" class="vwl-ebook__cta" data-action="read-again"><?php esc_html_e( 'Read again', 'wp-flip-book' ); ?></button>
				<a class="vwl-ebook__cta" data-ref="completeLink" href="#" hidden></a>
				<button type="button" class="vwl-ebook__cta vwl-ebook__cta--ghost" data-action="complete-close"><?php esc_html_e( 'Close', 'wp-flip-book' ); ?></button>
			</div>
		</div>
	</div>

	<div class="vwl-ebook__toast" data-ref="toast" role="status" hidden></div>

	<noscript><p class="vwl-ebook__noscript"><?php esc_html_e( 'Please enable JavaScript to read this book.', 'wp-flip-book' ); ?></p></noscript>
</div>
