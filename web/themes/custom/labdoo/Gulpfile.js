const gulp = require('gulp');
const sass = require('gulp-sass')(require('sass'));
const concat = require('gulp-concat');
const sourcemaps = require('gulp-sourcemaps');

// Task for compiling Sass.
function compileSass() {
  return gulp.src('sass/**/*.sass')
      .pipe(sourcemaps.init())
      .pipe(sass({
        outputStyle: 'compressed'
      }).on('error', sass.logError))
      .pipe(concat('main.css'))
      .pipe(sourcemaps.write('.'))
      .pipe(gulp.dest('css'));
}

// Task for watching changes in the Sass files.
function watch() {
  gulp.watch('sass/**/*.sass', compileSass);
}

// Gulp tasks.
exports.compileSass = compileSass;
exports.watch = watch;

// Default task.
exports.default = gulp.series(compileSass, watch);
