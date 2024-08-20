import gulp from 'gulp';
import gulpSass from 'gulp-sass';
import * as sass from 'sass'
import path from 'path';
import { fileURLToPath } from 'url';
import clean from 'gulp-clean';
import through2 from 'through2';

// Emulate __dirname for ES Modules.
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Paths.
const scssPath = path.join(__dirname, 'src/scss/**/*.scss');
const cssPath = path.join(__dirname, 'src/wp-logify/assets/css');
const pluginSourcePath = path.join(__dirname, 'src/wp-logify/**/*');
const pluginDestPath = path.join(__dirname, 'www/wp-content/plugins/wp-logify');

// Debug.
console.log('scssPath:', scssPath);
console.log('cssPath:', cssPath);
console.log('pluginSourcePath:', pluginSourcePath);
console.log('pluginDestPath:', pluginDestPath);

// Setup gulp-sass.
const sassProcessor = gulpSass(sass);

// Sass task.
gulp.task('sass', function () {
    return gulp.src(scssPath)
        .pipe(sassProcessor().on('error', sassProcessor.logError))
        .pipe(through2.obj(function (file, _, cb) {
            // Check if the CSS content is empty
            if (file.contents.toString().trim()) {
                // Proceed with writing the file.
                cb(null, file);
            } else {
                // Skip writing the file.
                cb();
            }
        }))
        .pipe(gulp.dest(cssPath));
});

// Clean plugin task.
gulp.task('clean-plugin', function () {
    return gulp.src(pluginDestPath, { read: false, allowEmpty: true })
        .pipe(clean());
});

// Copy plugin task.
gulp.task('copy-plugin', function () {
    return gulp.src(pluginSourcePath)
        .pipe(gulp.dest(pluginDestPath));
});

// Watch task.
gulp.task('watch', function () {
    gulp.watch(scssPath, { usePolling: true }, gulp.series('sass'));
    gulp.watch(pluginSourcePath, { usePolling: true }, gulp.series('clean-plugin', 'copy-plugin'));
});

gulp.task('default', gulp.series('sass', 'clean-plugin', 'copy-plugin', 'watch'));
