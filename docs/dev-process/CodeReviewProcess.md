# Code Review Process

## Initial Review

1.  Pipelines build for pull request is successful

1.  Unless pull request was specifically for updating composer.json and/or core.extensions.yml, pull request does <span class="underline">not</span> include:
    1.  composer.json
    2.  composer.lock
    3.  core.extensions.yml


1.  Does the pull request description clearly state the purpose of the feature and how it can be validated?

1.  Do the files included in the pull request appear to be related to feature, with no extraneous files?

1.  Is the pull request a manageable size (number of files and changes made)? Is it limited to one feature?

## Local Environment Check

 Before running drupal:sync, make sure you have the expected private files directory created within your Cloud IDE at  `/home/ide/project/files-private/default`

    $ git fetch
    $ git checkout feature/DRUP-123-implement-something
    $ git checkout develop
    $ git pull
    $ git checkout -b deleteme
    $ git merge feature/DRUP-123-implement-something
    $ blt drupal:sync

1.  Any errors during the this process?

1.  Log into Drupal and go to Reports\>\>Recent Log Messages. Clear the logs in the delete tab. Navigate to any pages affected by the feature. Return to the Recent Log Messages page. Are there any errors or messages in the log?

1.  Evaluate the changes using the information in the pull request description.

 **Local Environment Cleanup**
 
 After doing the local environment check and the code review, clean up your local environment by deleting the local copies of the deleteme branch used to test the result of merging the feature branch and by deleting the local copy of the feature branch.

    $ git checkout develop
    $ git pull
    $ git branch -D deleteme
    $ git branch
    $ git branch -D feature/DRUP-123-implement-something

 (line 4 is just to list the branches in your local environment to make deleting the feature branch easier)

## Final Review on Bitbucket

1. Take one last pass through the files looking at any changes.

1. For custom code, go through the custom code review checklist below.

1. Add any comments, even if you don’t find issues, that might help the developer improve.

1. If you find issues, make sure comment(s) clearly state the issues and what needs to be corrected, and click the Request Changes button at the top of the screen.

1. If no issues are found, click on the Approve button at the top of the screen. Unless there is a reason to hold off merging the branch, merge the branch, making sure the close the source branch option is **<span class="underline">NOT</span>** checked.

## Custom Code Review

1.  Does the module name follow project conventions if developing a custom module?

2.  Is the code following the Drupal Coding Standards, especially variable naming, [https://www.drupal.org/docs/develop/standards/coding-](https://www.drupal.org/docs/develop/standards/coding-standards#naming) [standards\#naming](https://www.drupal.org/docs/develop/standards/coding-standards#naming). Note the build checks for many, but not all, the coding standards. Developers and reviewers are encouraged to read the entire Drupal Coding Standard which includes not only PHP but also CSS and JavaScript, [https://www.drupal.org/docs/develop/standards](https://www.drupal.org/docs/develop/standards)

3.  Ensure the code contains a well-balanced amount of inline-comments.

4.  Is code well commented and clearly understandable by other developers.

5.  Check the Doxygen and comment formatting conventions.

6.  Are there tests for the custom code? Drupal uses PHPUnit for most PHP code testing that is not functional and Nightwatch.js for most JavaScript testing.

7.  Does the code seem reasonable (e.g. are there any code smells)? Look at the attached slide deck.

8.  Does the code accept user input without proper sanitization? Check code is using HTML::escape(), check\_markup(), or Xss::filter() to sanitize strings, if not, chances are that the code is vulnerable\!

9.  Are all strings (both constants and variables) wrapped in $this-\>t() which comes from [\\Drupal\\Core\\StringTranslation\\StringTranslationTra](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21StringTranslation%21StringTranslationTrait.php/function/StringTranslationTrait%3A%3At/9.1.x) [it](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21StringTranslation%21StringTranslationTrait.php/function/StringTranslationTrait%3A%3At/9.1.x), typically it is inherited from a parent class that already implements it. Otherwise create a new [\\Drupal\\Core\\StringTranslation\\TranslatableMarkup](https://api.drupal.org/api/drupal/core%21lib%21Drupal%21Core%21StringTranslation%21TranslatableMarkup.php/class/TranslatableMarkup/9.0.x) object directly. (the classic \\Drupal::t() function still works but eventually will be deprecated)

10. Look for debugging code that wasn't removed such as dd(), drupal\_debug() and other output functions.

11. Look for Git conflict symbols such as "\<\<\<", "\>\>\>" and "===". These usually indicate a botched conflict resolution.

12. Is code storing sensitive data anywhere it shouldn’t be?

13. Is code collecting or processing personally identifiable data? If yes, is it GDPR and other privacy regulations compatible?

14. Does the change break caching? Are there any unnecessary uses of $\_SESSION? By default, if the browser has a session, the request won’t be served by Varnish, but by the web server.

15. What’s the front end performance impact? How does the Lighthouse performance score change when the change is included?

16. Is it accessible? Will it work on a keyboard / screen reader / other input or output device? Can you navigate the page using only the keyboard?

17. Are there functions or objects that do more than one thing (e.g. have the word and in their description)? Are there functions or objects that do different things (e.g. they have the word or in their description)?

18. Are there functions that are excessively complex? Longer functions are an indication.

19. Is the purpose of a function or variable obvious from the name?

20. Is there any commented-out code? With very specific exceptions of capabilities that might be enabled or disabled locally, there shouldn’t be commented out code.

21. Does the code handle handling NULL and Empty objects? Are all reasonable error conditions handled?

22. Is there configuration that is hard coded?

23. Are there constants that should be defined using symbolic constants?

24. Does the code duplicate any Drupal API functionality?

A PDF of the slides from a talk by Larry Garfield at DrupalCon London 2011 about doing code reviews is at [https://london2011.drupal.org/sites/default/files/Code%20Smells_0.pdf](https://london2011.drupal.org/sites/default/files/Code%20Smells_0.pdf).  The video is at [https://www.youtube.com/watch?v=Z78ZP_UtaKE](https://www.youtube.com/watch?v=Z78ZP_UtaKE)

© 2020-2021. This work is licensed under a [Creative Commons Attribution-ShareAlike 4.0 International License](http://creativecommons.org/licenses/by-sa/4.0/).
